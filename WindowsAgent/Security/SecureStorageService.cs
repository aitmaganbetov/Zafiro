using System;
using System.Security.Cryptography;
using System.Text;

namespace ZafiroAgent.Security
{
    public class SecureStorageService
    {
        // Простейшая оболочка над DPAPI (ProtectedData)
        public string Protect(string plain)
        {
            if (plain == null) return string.Empty;
            var bytes = Encoding.UTF8.GetBytes(plain);
            var protectedBytes = ProtectedData.Protect(bytes, null, DataProtectionScope.CurrentUser);
            return Convert.ToBase64String(protectedBytes);
        }

        public string Unprotect(string protectedBase64)
        {
            if (string.IsNullOrEmpty(protectedBase64)) return string.Empty;
            try
            {
                var bytes = Convert.FromBase64String(protectedBase64);
                var plain = ProtectedData.Unprotect(bytes, null, DataProtectionScope.CurrentUser);
                return Encoding.UTF8.GetString(plain);
            }
            catch
            {
                return string.Empty;
            }
        }
    }
}
