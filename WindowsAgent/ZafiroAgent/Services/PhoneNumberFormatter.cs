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

        /// <summary>
        /// Цифры после «+7» по мере ввода, как на сайте: «700 123-45-67». Вставленный целиком
        /// номер с 7 или 8 в начале (11 цифр) теряет первую цифру; лишние цифры отбрасываются.
        /// </summary>
        public static string FormatLocal(string? input)
        {
            var digits = new string((input ?? string.Empty).Where(character => character is >= '0' and <= '9').ToArray());
            if (digits.Length == 11 && (digits[0] == '7' || digits[0] == '8'))
                digits = digits[1..];
            if (digits.Length > 10)
                digits = digits[..10];

            var text = new System.Text.StringBuilder(13);
            for (var i = 0; i < digits.Length; i++)
            {
                if (i == 3) text.Append(' ');
                else if (i is 6 or 8) text.Append('-');
                text.Append(digits[i]);
            }

            return text.ToString();
        }
    }
}