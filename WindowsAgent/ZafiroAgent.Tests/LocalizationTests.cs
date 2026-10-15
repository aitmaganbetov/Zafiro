using ZafiroAgent.Localization;

namespace ZafiroAgent.Tests;

/// <summary>Тексты агента: язык клиента, иначе клуба; нет перевода — английский, затем русский ключ.</summary>
public sealed class LocalizationTests : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "zafiro-i18n-" + Guid.NewGuid().ToString("N"));

    public LocalizationTests()
    {
        Directory.CreateDirectory(_dir);
        Write("ru", "Русский", """{"Войти":"Войти","Клуб: :club":"Клуб: :club","Только по-русски":"Только по-русски"}""");
        Write("kk", "Қазақша", """{"Войти":"Кіру","Клуб: :club":"Клуб: :club"}""");
        Write("en", "English", """{"Войти":"Sign in","Клуб: :club":"Club: :club","Только по-английски":"English only"}""");
        L.Reset();
        L.LoadBuiltIn(_dir);
    }

    public void Dispose()
    {
        L.Reset();
        try
        {
            Directory.Delete(_dir, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private void Write(string code, string name, string lines) =>
        File.WriteAllText(Path.Combine(_dir, code + ".json"), $$"""{"locale":"{{code}}","name":"{{name}}","lines":{{lines}}}""");

    [Fact]
    public void WithoutAClubLanguage_TheRussianSourceIsShown()
    {
        Assert.Equal("ru", L.Current);
        Assert.Equal("Войти", L.T("Войти"));
        Assert.Equal("Нет в каталоге", L.T("Нет в каталоге"));
    }

    [Fact]
    public void ClubLanguage_AppliesUntilACustomerPicksAnother_AndComesBackAfterSignOut()
    {
        L.SetClubLocale("kk");
        Assert.Equal("Кіру", L.T("Войти"));

        Assert.True(L.SetUserLocale("en"));
        Assert.Equal("Sign in", L.T("Войти"));

        L.SetUserLocale(null);
        Assert.Equal("kk", L.Current);
    }

    [Fact]
    public void MissingTranslation_FallsBackToEnglish_ThenToTheRussianKey()
    {
        L.SetClubLocale("kk");

        Assert.Equal("English only", L.T("Только по-английски"));
        Assert.Equal("Только по-русски", L.T("Только по-русски"));
    }

    [Fact]
    public void Placeholders_AreFilledInTheTranslation()
    {
        L.SetUserLocale("en");

        Assert.Equal("Club: Arena", L.T("Клуб: :club", ("club", "Arena")));
    }

    [Fact]
    public void UnknownLanguage_CannotBePicked()
    {
        Assert.False(L.SetUserLocale("zz"));
        Assert.False(L.SetUserLocale("../en"));
        Assert.Equal("ru", L.Current);
        Assert.Equal(new[] { "ru", "kk", "en" }, L.Languages.Select(l => l.Code));
    }

    [Fact]
    public void ServerEdits_OverrideShippedTexts_AndAreSentToThePageWithEnglishFallback()
    {
        L.SetClubLocale("kk");
        L.SetServerLines("kk", 7, new Dictionary<string, string> { ["Войти"] = "Жүйеге кіру" });

        Assert.Equal("Жүйеге кіру", L.T("Войти"));
        Assert.Equal(7, L.ServerVersion("kk"));
        var page = L.LinesFor("kk");
        Assert.Equal("Жүйеге кіру", page["Войти"]);
        Assert.Equal("English only", page["Только по-английски"]);
    }

    [Fact]
    public void ChangingTheLanguage_RaisesChanged_OnlyWhenSomethingChanges()
    {
        var raised = 0;
        void Count() => raised++;
        L.Changed += Count;
        try
        {
            L.SetClubLocale("kk");
            L.SetClubLocale("kk");
            L.SetUserLocale("en");
        }
        finally
        {
            L.Changed -= Count;
        }

        Assert.Equal(2, raised);
    }

    [Theory]
    [InlineData("""{"locale":"kk","version":3,"lines":{"Войти":"Кіру"}}""", true)]
    [InlineData("""{"locale":"KK","lines":{}}""", false)]
    [InlineData("""{"locale":"kk","lines":[]}""", false)]
    [InlineData("""not json""", false)]
    public void ServerResponse_IsAcceptedOnlyInTheExpectedShape(string json, bool valid)
    {
        Assert.Equal(valid, L.Parse(json) is not null);
    }
}
