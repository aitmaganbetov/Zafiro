using System.Linq;

namespace ZafiroAgent.Services
{
    public static class PhoneNumberFormatter
    {
        public static string? NormalizeKazakhstanNumber(string? input)
        {
            var digits = new string((input ?? string.Empty).Where(character => character is >= '0' and <= '9').ToArray());

            if (digits.Length == 11 && (digits[0] == '7' || digits[0] == '8'))
                digits = digits[1..];

            return digits.Length == 10 ? "+7" + digits : null;
        }
    }
}