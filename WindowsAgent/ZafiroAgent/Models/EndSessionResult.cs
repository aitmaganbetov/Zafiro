namespace ZafiroAgent.Models
{
    /// <summary>
    /// Ответ сервера на досрочное завершение сессии: сколько вернулось на основной и бонусный
    /// баланс (минуты, купленные поминутно) и сколько минут абонемента сохранено.
    /// </summary>
    public sealed record EndSessionResult(string Refunded, string RefundedBonus, int KeptMinutes);
}
