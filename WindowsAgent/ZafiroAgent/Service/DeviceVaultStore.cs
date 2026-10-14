using System;
using System.IO;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using ZafiroAgent.Services;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Токен ПК на стороне службы: %ProgramData%\ZafiroAgent\device.json. Папка доступна только
    /// SYSTEM и администраторам, а токен зашифрован DPAPI учётной записи службы (SYSTEM), поэтому
    /// клиент не может ни прочитать, ни удалить, ни подменить его. Папку, созданную заранее
    /// кем-то другим или подменённую ссылкой, служба не использует: откладывает её и создаёт заново.
    /// </summary>
    public sealed class DeviceVaultStore
    {
        private const string ServiceScope = "service";
        private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("ZafiroAgent.Vault");
        private readonly object _gate = new();
        private readonly string _path;
        private readonly Func<string?> _deviceId;

        public DeviceVaultStore()
            : this(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "ZafiroAgent"), restrictAccess: true, () => DeviceInfo.StableId)
        {
        }

        /// <param name="deviceId">UUID этого ПК; null — неизвестен (тогда токен к ПК не привязывается).</param>
        public DeviceVaultStore(string directory, bool restrictAccess, Func<string?>? deviceId = null)
        {
            var dir = new DirectoryInfo(directory);
            if (restrictAccess) CreateTrusted(dir);
            else dir.Create();
            _path = Path.Combine(dir.FullName, "device.json");
            _deviceId = deviceId ?? (() => null);
        }

        /// <summary>UUID этого ПК (SMBIOS): служба отправляет его серверу вместе с токеном.</summary>
        public string? DeviceId => _deviceId();

        /// <summary>
        /// Токен этого ПК. Токен, сохранённый на другом ПК (бездисковая система: образ снят
        /// с одного ПК и загружается на всех), этому ПК не принадлежит — возвращается null.
        /// </summary>
        public string? Load()
        {
            lock (_gate)
            {
                var stored = Read();
                if (stored is null || !SameDevice(stored.DeviceId)) return null;
                var token = Unseal(stored.Token);
                if (!VaultProtocol.IsDeviceToken(token)) return null;

                // Версии 0.0.16–0.0.17 шифровали ключом компьютера: перешифровываем ключом службы.
                if (stored.Scope != ServiceScope)
                {
                    try { SaveUnlocked(token!); }
                    catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
                }

                return token;
            }
        }

        public void Save(string token)
        {
            lock (_gate) SaveUnlocked(token);
        }

        /// <summary>Сервер отверг токен (отозван или с другого ПК): он удаляется, ключ клуба остаётся.</summary>
        public void Clear()
        {
            lock (_gate)
            {
                var stored = Read();
                if (stored is null) return;
                stored.Token = null;
                stored.DeviceId = null;
                Write(stored);
            }
        }

        /// <summary>Ключ установки клуба: по нему служба сама получает токен своего ПК.</summary>
        public string? LoadClubKey()
        {
            lock (_gate)
            {
                var key = Unseal(Read()?.ClubKey);
                return VaultProtocol.IsClubKey(key) ? key : null;
            }
        }

        public void SaveClubKey(string key)
        {
            lock (_gate)
            {
                var stored = Read() ?? new Stored();
                stored.ClubKey = Seal(key);
                Write(stored);
            }
        }

        private void SaveUnlocked(string token)
        {
            var stored = Read() ?? new Stored();
            stored.Token = Seal(token);
            stored.Scope = ServiceScope;
            stored.DeviceId = DeviceId;
            stored.SavedAtUtc = DateTime.UtcNow;
            Write(stored);
        }

        // Старый файл без UUID принимается: если он скопирован с другого ПК, его отвергнет сервер.
        private bool SameDevice(string? storedDeviceId)
        {
            var current = DeviceId;
            return storedDeviceId is null || current is null || string.Equals(storedDeviceId, current, StringComparison.OrdinalIgnoreCase);
        }

        private Stored? Read()
        {
            try
            {
                return File.Exists(_path) ? JsonSerializer.Deserialize<Stored>(File.ReadAllText(_path)) : null;
            }
            catch (Exception ex) when (ex is IOException or JsonException or UnauthorizedAccessException)
            {
                return null;
            }
        }

        private void Write(Stored stored)
        {
            var temp = _path + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(stored));
            File.Move(temp, _path, overwrite: true);
        }

        private static string Seal(string plain) =>
            Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(plain), Entropy, DataProtectionScope.CurrentUser));

        private static string? Unseal(string? sealedValue)
        {
            if (string.IsNullOrEmpty(sealedValue)) return null;
            try
            {
                return Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(sealedValue), Entropy, DataProtectionScope.CurrentUser));
            }
            catch (Exception ex) when (ex is FormatException or CryptographicException)
            {
                return null;
            }
        }

        /// <summary>
        /// Папка, которой владеют SYSTEM или администраторы, без ссылок (junction). Чужая папка
        /// (клиент может создать её в ProgramData до установки) откладывается, создаётся новая.
        /// </summary>
        private static void CreateTrusted(DirectoryInfo dir)
        {
            for (var attempt = 0; attempt < 3; attempt++)
            {
                dir.Refresh();
                if (dir.Exists && !IsTrusted(dir))
                {
                    var aside = dir.FullName + ".untrusted-" + Guid.NewGuid().ToString("N");
                    if ((dir.Attributes & FileAttributes.ReparsePoint) != 0) Directory.Delete(dir.FullName); // удаляется только ссылка
                    else Directory.Move(dir.FullName, aside);
                    SafeLog.Write("[Service] untrusted vault folder replaced");
                    dir.Refresh();
                }

                if (!dir.Exists) dir.Create(Restricted());
                else dir.SetAccessControl(Restricted());

                dir.Refresh();
                if (IsTrusted(dir)) return;
            }

            throw new UnauthorizedAccessException("The vault folder could not be secured.");
        }

        private static bool IsTrusted(DirectoryInfo dir)
        {
            if ((dir.Attributes & FileAttributes.ReparsePoint) != 0) return false;
            var owner = dir.GetAccessControl().GetOwner(typeof(SecurityIdentifier)) as SecurityIdentifier;
            return owner is not null && (owner.IsWellKnown(WellKnownSidType.LocalSystemSid) || owner.IsWellKnown(WellKnownSidType.BuiltinAdministratorsSid));
        }

        // Наследование прав отключено: обычные пользователи не видят содержимое папки.
        private static DirectorySecurity Restricted()
        {
            var security = new DirectorySecurity();
            security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
            foreach (var sid in new[] { WellKnownSidType.LocalSystemSid, WellKnownSidType.BuiltinAdministratorsSid })
            {
                security.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(sid, null), FileSystemRights.FullControl,
                    InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
            }

            return security;
        }

        private sealed class Stored
        {
            public string? Token { get; set; }
            public string? Scope { get; set; }
            public DateTime SavedAtUtc { get; set; }

            // UUID ПК, на котором сохранён токен, и ключ установки клуба (зашифрован так же, как токен).
            public string? DeviceId { get; set; }
            public string? ClubKey { get; set; }
        }
    }
}
