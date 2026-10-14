using System.Net;
using System.Text.Json;
using ZafiroAgent.Kiosk;
using ZafiroAgent.Models;
using ZafiroAgent.Services;

namespace ZafiroAgent.Tests;

public sealed class KioskTests
{
    [Theory]
    [InlineData(KioskKeyboard.VkLWin, false, false, false)]
    [InlineData(KioskKeyboard.VkRWin, false, false, false)]
    [InlineData(KioskKeyboard.VkApps, false, false, false)]
    [InlineData(KioskKeyboard.VkTab, true, false, false)]      // Alt+Tab
    [InlineData(KioskKeyboard.VkEscape, true, false, false)]   // Alt+Esc
    [InlineData(KioskKeyboard.VkF4, true, false, false)]       // Alt+F4
    [InlineData(KioskKeyboard.VkSpace, true, false, false)]    // Alt+Пробел
    [InlineData(KioskKeyboard.VkEscape, false, true, false)]   // Ctrl+Esc
    [InlineData(KioskKeyboard.VkEscape, false, true, true)]    // Ctrl+Shift+Esc
    public void WithoutPaidSession_ExitShortcutsAreBlocked(int vk, bool alt, bool ctrl, bool shift)
    {
        Assert.True(KioskKeyboard.ShouldBlock(vk, alt, ctrl, shift, strict: true));
    }

    [Theory]
    [InlineData(KioskKeyboard.VkLWin, false, false, false)]
    [InlineData(KioskKeyboard.VkTab, true, false, false)]
    [InlineData(KioskKeyboard.VkF4, true, false, false)]
    public void DuringPaidSession_TheCustomerUsesThePcNormally(int vk, bool alt, bool ctrl, bool shift)
    {
        Assert.False(KioskKeyboard.ShouldBlock(vk, alt, ctrl, shift, strict: false));
    }

    [Fact]
    public void TaskManagerShortcut_IsBlockedAlways_AndOrdinaryKeysNever()
    {
        Assert.True(KioskKeyboard.ShouldBlock(KioskKeyboard.VkEscape, alt: false, ctrl: true, shift: true, strict: false));
        Assert.False(KioskKeyboard.ShouldBlock('A', alt: false, ctrl: false, shift: false, strict: true));
        Assert.False(KioskKeyboard.ShouldBlock(KioskKeyboard.VkTab, alt: false, ctrl: false, shift: false, strict: true));
    }

    [Fact]
    public void CtrlAltP_IsTheStaffShortcut()
    {
        Assert.True(KioskKeyboard.IsStaffShortcut(KioskKeyboard.VkP, alt: true, ctrl: true));
        Assert.False(KioskKeyboard.IsStaffShortcut(KioskKeyboard.VkP, alt: false, ctrl: true));
        Assert.False(KioskKeyboard.IsStaffShortcut(KioskKeyboard.VkP, alt: true, ctrl: false));
    }

    [Fact]
    public async Task ExitPin_IsCheckedOnTheServerWithTheDeviceToken()
    {
        const string device = "zag_device";
        var handler = new StubHandler((request, body) =>
        {
            Assert.Equal("https://zafiro.kz/api/agent/exit-pin", request.RequestUri!.AbsoluteUri);
            Assert.Null(request.Headers.Authorization);
            using var json = JsonDocument.Parse(body!);
            return json.RootElement.GetProperty("pin").GetString() == "4821"
                ? StubHandler.Json(HttpStatusCode.OK, """{"valid":true}""")
                : StubHandler.Json(HttpStatusCode.UnprocessableEntity, """{"valid":false,"code":"invalid_pin"}""");
        });
        var api = new ZafiroApiClient(new HttpClient(handler));

        Assert.True((await api.VerifyExitPinAsync(device, "4821", CancellationToken.None)).Success);
        var wrong = await api.VerifyExitPinAsync(device, "0000", CancellationToken.None);

        Assert.False(wrong.Success);
        Assert.Equal("Неверный PIN-код.", wrong.Message);
        Assert.All(handler.Requests, r => Assert.Equal(device, r.AgentToken));
    }

    [Theory]
    [InlineData(HttpStatusCode.Conflict, ApiFailure.NotConfigured)]
    [InlineData(HttpStatusCode.TooManyRequests, ApiFailure.Forbidden)]
    [InlineData(HttpStatusCode.Unauthorized, ApiFailure.DeviceTokenInvalid)]
    public async Task ExitPin_ServerRefusalsAreExplained(HttpStatusCode status, ApiFailure failure)
    {
        var api = new ZafiroApiClient(new HttpClient(new StubHandler((_, _) => StubHandler.Json(status, "{}"))));

        var result = await api.VerifyExitPinAsync("zag_device", "4821", CancellationToken.None);

        Assert.False(result.Success);
        Assert.Equal(failure, result.Failure);
    }

    [Fact]
    public async Task ExitPin_WithoutDeviceToken_IsNotSentAtAll()
    {
        var handler = new StubHandler((_, _) => throw new InvalidOperationException("no request expected"));
        var api = new ZafiroApiClient(new HttpClient(handler));

        var result = await api.VerifyExitPinAsync(null, "4821", CancellationToken.None);

        Assert.Equal(ApiFailure.DeviceTokenInvalid, result.Failure);
        Assert.Empty(handler.Requests);
    }
}
