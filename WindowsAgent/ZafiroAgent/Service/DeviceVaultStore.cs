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

        public DeviceVaultStore()
            : this(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "ZafiroAgent"), restrictAccess: true)
        {
        }

        public DeviceVaultStore(string directory, bool restrictAccess)
        {
            var dir = new DirectoryInfo(directory);
            if (restrictAccess) CreateTrusted(dir);
            else dir.Create();
            _path = Path.Combine(dir.FullName, "device.json");
        }

        public string? Load()
        {
            lock (_gate)
            {
                try
                {
                    if (!File.Exists(_path)) return null;
                    var stored = JsonSerializer.Deserialize<Stored>(File.ReadAllText(_path));
                    if (string.IsNullOrEmpty(stored?.Token)) return null;
                    var plain = ProtectedData.Unprotect(Convert.FromBase64String(stored.Token), Entropy, DataProtectionScope.CurrentUser);
                    var token = Encoding.UTF8.GetString(plain);
                    if (!VaultProtocol.IsDeviceToken(token)) return null;

                    // Версии 0.0.16–0.0.17 шифровали ключом компьютера: перешифровываем ключом службы.
                    if (stored.Scope != ServiceScope) SaveUnlocked(token);
                    return token;
                }
                catch (Exception ex) when (ex is IOException or JsonException or FormatException or CryptographicException or UnauthorizedAccessException)
                {
                    return null;
                }
            }
        }

        public void Save(string token)
        {
            lock (_gate) SaveUnlocked(token);
        }

        private void SaveUnlocked(string token)
        {
            var sealedToken = ProtectedData.Protect(Encoding.UTF8.GetBytes(token), Entropy, DataProtectionScope.CurrentUser);
            var temp = _path + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(new Stored { Token = Convert.ToBase64String(sealedToken), Scope = ServiceScope, SavedAtUtc = DateTime.UtcNow }));
            File.Move(temp, _path, overwrite: true);
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
        }
    }
}
