using System;
using System.IO;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;

namespace ZafiroAgent.Service
{
    /// <summary>
    /// Токен ПК на стороне службы: %ProgramData%\ZafiroAgent\device.json. Папка доступна только
    /// SYSTEM и администраторам, токен дополнительно зашифрован DPAPI компьютера, поэтому
    /// клиент не может ни прочитать, ни удалить, ни подменить его.
    /// </summary>
    public sealed class DeviceVaultStore
    {
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
            dir.Create();
            if (restrictAccess) RestrictToSystemAndAdministrators(dir);
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
                    var plain = ProtectedData.Unprotect(Convert.FromBase64String(stored.Token), Entropy, DataProtectionScope.LocalMachine);
                    var token = Encoding.UTF8.GetString(plain);
                    return VaultProtocol.IsDeviceToken(token) ? token : null;
                }
                catch (Exception ex) when (ex is IOException or JsonException or FormatException or CryptographicException or UnauthorizedAccessException)
                {
                    return null;
                }
            }
        }

        public void Save(string token)
        {
            lock (_gate)
            {
                var sealedToken = ProtectedData.Protect(Encoding.UTF8.GetBytes(token), Entropy, DataProtectionScope.LocalMachine);
                var temp = _path + ".tmp";
                File.WriteAllText(temp, JsonSerializer.Serialize(new Stored { Token = Convert.ToBase64String(sealedToken), SavedAtUtc = DateTime.UtcNow }));
                File.Move(temp, _path, overwrite: true);
            }
        }

        // Наследование прав отключено: обычные пользователи не видят содержимое папки.
        private static void RestrictToSystemAndAdministrators(DirectoryInfo dir)
        {
            var security = new DirectorySecurity();
            security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
            foreach (var sid in new[] { WellKnownSidType.LocalSystemSid, WellKnownSidType.BuiltinAdministratorsSid })
            {
                security.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(sid, null), FileSystemRights.FullControl,
                    InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
            }

            dir.SetAccessControl(security);
        }

        private sealed class Stored
        {
            public string? Token { get; set; }
            public DateTime SavedAtUtc { get; set; }
        }
    }
}
