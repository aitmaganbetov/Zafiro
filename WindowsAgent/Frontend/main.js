(() => {
  let products = [];

  // Тексты интерфейса: ключ — русский текст, переводы присылает агент (сообщение i18n).
  // Пока их нет, показывается русский текст.
  let lines = {};
  let locale = 'ru';
  let languages = [];
  const intlLocales = { ru: 'ru-RU', kk: 'kk-KZ', en: 'en-GB' };
  const languageLabels = { ru: 'RU', kk: 'ҚАЗ', en: 'EN' };
  const intlLocale = () => intlLocales[locale] ?? locale;

  const fill = (text, params) => Object.keys(params)
    .sort((a, b) => b.length - a.length) // :count не должен задеть :countX
    .reduce((result, name) => result.split(`:${name}`).join(String(params[name])), text);
  const t = (key, params = {}) => fill(lines[key] ?? key, params);

  // Формы через «|» в порядке категорий Intl.PluralRules: ru — one|few|many, en — one|other, kk — одна форма.
  const pluralOrder = ['zero', 'one', 'two', 'few', 'many', 'other'];
  const tc = (key, count, params = {}) => {
    const forms = (lines[key] ?? key).split('|');
    const rules = new Intl.PluralRules(intlLocale());
    const order = pluralOrder.filter((category) => rules.resolvedOptions().pluralCategories.includes(category));
    const index = Math.max(0, Math.min(order.indexOf(rules.select(count)), forms.length - 1));
    return fill(forms[index], { count, ...params });
  };

  const normalizeCategory = (value) => {
    const category = String(value ?? '').toLowerCase();
    if (category.includes('нап')) return 'drinks';
    if (category.includes('пер') || category.includes('ед') || category.includes('пищ')) return 'food';
    if (category.includes('акс') || category.includes('тех') || category.includes('игр')) return 'gear';
    return 'other'; // видно в «Все», а не среди закусок
  };

  // Локальная заглушка: без сети картинка не превращается в «битую».
  const placeholderImage = 'product-placeholder.svg';
  const normalizeProduct = (product) => ({
    id: product.id ?? product.sku,
    name: product.name ?? null,
    description: product.description ?? product.category ?? product.sku ?? null,
    category: normalizeCategory(product.category),
    price: Number(product.price ?? 0),
    currency: product.currency ?? 'KZT',
    quantity: Number(product.quantity ?? 0),
    inStock: Boolean(product.in_stock ?? product.inStock),
    image: product.image_url ?? product.image ?? placeholderImage
  });
  const productName = (product) => product.name ?? t('Товар');

  const cart = new Map();
  const shopCatalog = document.getElementById('shopCatalog');
  const sessionPage = document.getElementById('sessionPage');
  const shopPage = document.getElementById('shopPage');
  const historyPage = document.getElementById('historyPage');
  const tariffPage = document.getElementById('tariffPage');
  const profilePage = document.getElementById('profilePage');
  const tariffCatalog = document.getElementById('tariffCatalog');
  const tariffPageStatus = document.getElementById('tariffPageStatus');
  const pageToggle = document.getElementById('pageToggle');
  const homeNavigation = document.getElementById('homeNavigation');
  const shopNavigation = document.getElementById('shopNavigation');
  const historyNavigation = document.getElementById('historyNavigation');
  const tariffNavigation = document.getElementById('tariffNavigation');
  const profileNavigation = document.getElementById('profileNavigation');
  const cartDialog = document.getElementById('cartDialog');
  const actionStatus = document.getElementById('actionStatus');
  const shopCount = document.getElementById('shopCount');
  const webview = window.chrome?.webview;
  let activeCategory = 'all';
  let currentView = 'session';
  let lastTariffs = null;
  let productsFailed = false;
  let userName = null; // null — гость
  let sessionStartedAt = null;

  if (!shopCatalog) return;

  // Сообщения, показанные по ключу, при смене языка показываются заново; текст сервера — как есть.
  const statusKeys = new Map();
  const setStatus = (element, key, params = {}) => {
    statusKeys.set(element, [key, params]);
    element.textContent = key ? t(key, params) : '';
  };
  const setServerStatus = (element, text) => {
    statusKeys.delete(element);
    element.textContent = text;
  };

  const fetchProducts = () => {
    setStatus(shopCount, 'Загружаем товары…');
    if (webview) webview.postMessage({ type: 'requestProducts' });
    else setStatus(shopCount, 'Запустите приложение, чтобы загрузить товары.');
  };

  const formatPrice = (value, currency = 'KZT') => `${new Intl.NumberFormat(intlLocale()).format(value)} ${currency}`;
  const formatDateTime = (value) => new Intl.DateTimeFormat(intlLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));

  // Часы сервера минус часы ПК: оставшееся время не зависит от неверных часов ПК.
  let clockOffset = 0;
  const serverNow = () => Date.now() + clockOffset;

  let balances = [null, null];
  const formatBalance = (value) => value === null ? '—' : Number(value ?? 0).toLocaleString(intlLocale(), { maximumFractionDigits: 2 });
  const applyBalances = (balance, bonus) => {
    balances = [balance, bonus];
    const text = { balanceBig: balance, historyBalance: balance, profileBalance: balance, bonusSmall: bonus, historyBonus: bonus, profileBonus: bonus };
    Object.entries(text).forEach(([id, value]) => {
      document.getElementById(id).textContent = formatBalance(value);
    });
  };

  const setView = (view) => {
    currentView = view;
    const showShop = view === 'shop';
    const showHistory = view === 'history';
    const showTariffs = view === 'tariffs';
    const showProfile = view === 'profile';
    sessionPage.hidden = showShop || showHistory || showTariffs || showProfile;
    shopPage.hidden = !showShop;
    historyPage.hidden = !showHistory;
    tariffPage.hidden = !showTariffs;
    profilePage.hidden = !showProfile;
    pageToggle.querySelector('span').textContent = view === 'session' ? t('Магазин') : t('К сессии');
    pageToggle.setAttribute('aria-label', view === 'session' ? t('Открыть магазин') : t('Вернуться к сессии'));
    homeNavigation.setAttribute('aria-current', view === 'session' ? 'page' : 'false');
    shopNavigation.setAttribute('aria-current', showShop ? 'page' : 'false');
    historyNavigation.setAttribute('aria-current', showHistory ? 'page' : 'false');
    tariffNavigation.setAttribute('aria-current', showTariffs ? 'page' : 'false');
    profileNavigation.setAttribute('aria-current', showProfile ? 'page' : 'false');
  };

  const createActionButton = (label, className, action, accessibleLabel = label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = label;
    button.setAttribute('aria-label', accessibleLabel);
    button.addEventListener('click', action);
    return button;
  };

  const renderProducts = () => {
    const visibleProducts = activeCategory === 'all'
      ? products
      : products.filter((product) => product.category === activeCategory);

    shopCatalog.replaceChildren();
    if (visibleProducts.length) setServerStatus(shopCount, tc(':count товар|:count товара|:count товаров', visibleProducts.length));
    else setStatus(shopCount, productsFailed && !products.length ? 'Не удалось загрузить товары. Проверьте подключение.' : 'Товары не загружены');

    visibleProducts.forEach((product) => {
      const card = document.createElement('article');
      card.className = 'shop-product';

      const cover = document.createElement('div');
      cover.className = 'shop-product-cover';

      const image = document.createElement('img');
      image.src = product.image;
      image.alt = productName(product);
      image.loading = 'lazy';
      image.addEventListener('error', () => {
        if (!image.src.endsWith(placeholderImage)) image.src = placeholderImage;
      }, { once: true });

      const details = document.createElement('div');
      details.className = 'shop-product-details';

      const name = document.createElement('h3');
      name.textContent = productName(product);

      const description = document.createElement('p');
      description.textContent = product.description ?? t('Товар');

      const footer = document.createElement('div');
      footer.className = 'shop-product-footer';

      const price = document.createElement('strong');
      price.textContent = formatPrice(product.price, product.currency);

      const quantity = cart.get(product.id) || 0;
      const addButton = createActionButton(
        quantity ? t('В корзине · :count', { count: quantity }) : t('В корзину'),
        'shop-buy',
        () => changeQuantity(product.id, 1),
        t('Добавить :product в корзину', { product: productName(product) })
      );
      footer.append(price, addButton);
      details.append(name, description, footer);
      cover.append(image);
      card.append(cover, details);
      shopCatalog.append(card);
    });
  };

  const renderCartItems = (container, emptyElement) => {
    container.replaceChildren();
    const entries = [...cart.entries()];
    emptyElement.hidden = entries.length > 0;

    entries.forEach(([productId, quantity]) => {
      const product = products.find((item) => item.id === productId);
      if (!product) return;

      const row = document.createElement('div');
      row.className = 'cart-item';

      const info = document.createElement('div');
      info.className = 'cart-item-info';

      const name = document.createElement('strong');
      name.textContent = productName(product);

      const price = document.createElement('span');
      price.textContent = formatPrice(product.price * quantity);
      info.append(name, price);

      const controls = document.createElement('div');
      controls.className = 'quantity-controls';
      controls.append(
        createActionButton('−', 'quantity-button', () => changeQuantity(productId, -1), t('Уменьшить количество: :product', { product: productName(product) })),
        Object.assign(document.createElement('span'), { textContent: String(quantity) }),
        createActionButton('+', 'quantity-button', () => changeQuantity(productId, 1), t('Увеличить количество: :product', { product: productName(product) }))
      );
      row.append(info, controls);
      container.append(row);
    });
  };

  const renderCart = () => {
    const totalQuantity = [...cart.values()].reduce((sum, quantity) => sum + quantity, 0);
    const totalPrice = [...cart.entries()].reduce((sum, [productId, quantity]) => {
      const product = products.find((item) => item.id === productId);
      return sum + (product ? product.price * quantity : 0);
    }, 0);

    document.getElementById('cartCount').textContent = String(totalQuantity);
    document.getElementById('cartPanelCount').textContent = String(totalQuantity);
    document.getElementById('cartTotal').textContent = formatPrice(totalPrice);
    document.getElementById('dialogCartTotal').textContent = formatPrice(totalPrice);
    renderCartItems(document.getElementById('cartItems'), document.getElementById('cartEmpty'));
    renderCartItems(document.getElementById('dialogCartItems'), document.getElementById('dialogCartEmpty'));
    renderProducts();
  };

  function changeQuantity(productId, change) {
    const nextQuantity = (cart.get(productId) || 0) + change;
    if (nextQuantity > 0) cart.set(productId, nextQuantity);
    else cart.delete(productId);
    renderCart();
  }

  const tariffValue = (tariff, property) => tariff[property] ?? tariff[property[0].toUpperCase() + property.slice(1)];
  const formatTariffDuration = (minutes) => {
    const value = Number(minutes) || 0;
    if (value < 60 || value % 60 !== 0) return t(':count мин', { count: value });
    return tc(':count час|:count часа|:count часов', value / 60);
  };

  const renderTariffs = (tariffs) => {
    lastTariffs = tariffs;
    tariffCatalog.replaceChildren();

    if (!tariffs.length) {
      setStatus(tariffPageStatus, 'Сейчас тарифы недоступны.');
      return;
    }

    setStatus(tariffPageStatus, null);
    tariffs.forEach((tariff) => {
      const card = document.createElement('article');
      card.className = 'tariff-card';

      const duration = document.createElement('span');
      duration.className = 'tariff-duration';
      duration.textContent = formatTariffDuration(tariffValue(tariff, 'minutes'));

      const name = document.createElement('h2');
      name.textContent = tariffValue(tariff, 'name') || t('Игровой тариф');

      const description = document.createElement('p');
      description.textContent = tariffValue(tariff, 'description') || t(':duration игры', { duration: formatTariffDuration(tariffValue(tariff, 'minutes')) });

      const footer = document.createElement('div');
      footer.className = 'tariff-card-footer';

      const price = document.createElement('strong');
      price.textContent = formatPrice(tariffValue(tariff, 'price'), tariffValue(tariff, 'currency') || 'KZT');

      const button = document.createElement('button');
      button.className = 'tariff-purchase';
      button.type = 'button';
      button.textContent = t('Выбрать');
      button.addEventListener('click', () => {
        const tariffId = tariffValue(tariff, 'id');
        if (tariffId === undefined || !webview) {
          setStatus(tariffPageStatus, 'Покупка доступна в приложении.');
          return;
        }

        setStatus(tariffPageStatus, 'Оформляем тариф…');
        tariffCatalog.querySelectorAll('.tariff-purchase').forEach((item) => {
          item.disabled = true;
        });
        webview.postMessage({ type: 'purchaseTariff', tariffId: String(tariffId) });
      });

      footer.append(price, button);
      card.append(duration, name, description, footer);
      tariffCatalog.append(card);
    });
  };

  document.getElementById('shopCategories').addEventListener('click', (event) => {
    const button = event.target.closest('[data-category]');
    if (!button) return;

    activeCategory = button.dataset.category;
    document.querySelectorAll('.category-button').forEach((item) => {
      const isActive = item === button;
      item.classList.toggle('is-active', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });
    renderProducts();
  });

  document.getElementById('cartToggle').addEventListener('click', () => {
    if (typeof cartDialog.showModal === 'function') cartDialog.showModal();
    else cartDialog.setAttribute('open', '');
  });
  document.getElementById('cartClose').addEventListener('click', () => cartDialog.close());
  document.getElementById('clearCart').addEventListener('click', () => {
    cart.clear();
    renderCart();
  });
  cartDialog.addEventListener('click', (event) => {
    if (event.target === cartDialog) cartDialog.close();
  });

  setView('session');
  document.getElementById('btnReturn')?.addEventListener('click', () => {
    webview?.postMessage({ type: 'returnToDesktop' });
  });
  document.getElementById('btnExtend')?.addEventListener('click', () => setView('tariffs'));
  document.getElementById('btnTopUp')?.addEventListener('click', () => {
    setStatus(actionStatus, 'Пополнить баланс можно на кассе клуба.');
  });
  const endButton = document.getElementById('btnEnd');
  const endButtonLabel = endButton.querySelector('span');
  const endButtonIcon = document.getElementById('endIconPath');
  const endIconPaths = {
    end: 'M12 3v9m6.4-5.4a8 8 0 1 1-12.8 0',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9'
  };
  let hasActiveSession = false;
  let sessionEndsAt = null;

  // «1 ч 27 мин» вместо «87»: так оставшееся время читается с одного взгляда.
  const formatMinutes = (minutes) => {
    const value = Math.max(0, Math.round(Number(minutes) || 0));
    const hours = Math.floor(value / 60);
    const rest = value % 60;
    return hours
      ? t(':hours ч :minutes мин', { hours, minutes: String(rest).padStart(2, '0') })
      : t(':count мин', { count: rest });
  };

  // Оставшееся время по данным сервера: обновляется на экране, пока сессия идёт.
  const showSessionTime = () => {
    if (!sessionEndsAt) return;
    const remainingMinutes = Math.max(0, Math.ceil((sessionEndsAt - serverNow()) / 60000));
    document.getElementById('remainingBig').textContent = formatMinutes(remainingMinutes);
    document.getElementById('profileRemaining').textContent = formatMinutes(remainingMinutes);
    document.getElementById('sessionExpires').textContent = formatDateTime(sessionEndsAt);
  };
  setInterval(showSessionTime, 15000);

  const sessionEndMessages = {
    expired: 'Время сессии закончилось. Выберите тариф, чтобы продолжить.',
    server: 'Сессия завершена в клубе.',
    ended: 'Сессия завершена.',
    none: null
  };

  const showProfileStatus = () => {
    document.getElementById('profileStatus').textContent = hasActiveSession ? t('Активная сессия') : t('Нет активной сессии');
  };

  const setSessionActive = (active) => {
    hasActiveSession = active;
    endButton.disabled = false;
    endButtonLabel.textContent = active ? t('Завершить сессию') : t('Выйти');
    endButtonIcon.setAttribute('d', active ? endIconPaths.end : endIconPaths.logout);
    document.getElementById('extendLabel').textContent = active ? t('Продлить сессию') : t('Выбрать тариф');
    showProfileStatus();
  };

  const showStartedAt = () => {
    document.getElementById('profileStartedAt').textContent = sessionStartedAt ? formatDateTime(sessionStartedAt) : '—';
  };

  const clearSessionDetails = () => {
    sessionEndsAt = null;
    sessionStartedAt = null;
    document.getElementById('remainingBig').textContent = '—';
    document.getElementById('sessionExpires').textContent = '—';
    document.getElementById('profileTariff').textContent = '—';
    document.getElementById('profileRemaining').textContent = '—';
    delete document.getElementById('profileRemaining').dataset.minutes;
    showStartedAt();
    showProfileStatus();
  };

  endButton.addEventListener('click', () => {
    if (!webview) {
      setStatus(actionStatus, 'Действие доступно только в приложении.');
      return;
    }

    endButton.disabled = true;
    setStatus(actionStatus, hasActiveSession ? 'Завершаем сессию…' : 'Выходим…');
    // endSession не разлогинивает пользователя, logout — разлогинивает.
    webview.postMessage({ type: hasActiveSession ? 'endSession' : 'logout' });
  });

  homeNavigation.addEventListener('click', () => setView('session'));
  shopNavigation.addEventListener('click', () => setView('shop'));
  historyNavigation.addEventListener('click', () => setView('history'));
  tariffNavigation.addEventListener('click', () => setView('tariffs'));
  profileNavigation.addEventListener('click', () => setView('profile'));
  pageToggle.addEventListener('click', () => setView(currentView === 'session' ? 'shop' : 'session'));

  function showProfileName() {
    const name = userName ?? t('Гость');
    document.getElementById('userName').textContent = name;
    document.getElementById('profileDisplayName').textContent = name;
    document.getElementById('profileAvatarText').textContent = name.trim().charAt(0).toLocaleUpperCase(intlLocale()) || '?';
  }

  const showPcLabel = () => {
    const label = document.getElementById('pcLabel');
    if (!label.dataset.number) return;
    label.textContent = t('ПК :number', { number: label.dataset.number }) + (label.dataset.zone ? ' · ' + label.dataset.zone : '');
    label.hidden = false;
  };

  // Язык выбирает клиент; после его выхода агент возвращает язык клуба.
  const renderLanguageSwitch = () => {
    const box = document.getElementById('langSwitch');
    box.replaceChildren(...languages.map(({ code, name }) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'lang-button';
      button.textContent = languageLabels[code] ?? code.toUpperCase();
      button.title = name;
      button.lang = code;
      button.setAttribute('aria-pressed', String(code === locale));
      button.addEventListener('click', () => {
        if (code !== locale) webview?.postMessage({ type: 'setLanguage', locale: code });
      });
      return button;
    }));
    box.hidden = languages.length < 2;
  };

  const applyLanguage = () => {
    document.documentElement.lang = locale;
    document.querySelectorAll('[data-i18n]').forEach((element) => {
      element.textContent = t(element.dataset.i18n);
    });
    [['data-i18n-aria-label', 'aria-label'], ['data-i18n-title', 'title'], ['data-i18n-alt', 'alt']].forEach(([source, target]) => {
      document.querySelectorAll(`[${source}]`).forEach((element) => element.setAttribute(target, t(element.getAttribute(source))));
    });
    statusKeys.forEach(([key, params], element) => {
      element.textContent = key ? t(key, params) : '';
    });
    setView(currentView);
    setSessionActive(hasActiveSession);
    showProfileName();
    showStartedAt();
    applyBalances(...balances);
    renderCart();
    if (lastTariffs) renderTariffs(lastTariffs);
    if (sessionEndsAt) showSessionTime();
    else if (document.getElementById('profileRemaining').dataset.minutes) {
      document.getElementById('profileRemaining').textContent = formatMinutes(Number(document.getElementById('profileRemaining').dataset.minutes));
    }
    showPcLabel();
    renderLanguageSwitch();
  };

  webview?.addEventListener('message', (event) => {
    if (event.data?.type === 'i18n') {
      lines = event.data.lines && typeof event.data.lines === 'object' ? event.data.lines : {};
      locale = typeof event.data.locale === 'string' ? event.data.locale : 'ru';
      languages = Array.isArray(event.data.languages) ? event.data.languages.filter((item) => item && typeof item.code === 'string') : [];
      applyLanguage();
      return;
    }

    if (event.data?.type === 'products') {
      // Без id два товара с одинаковым названием попали бы в одну строку корзины.
      products = Array.isArray(event.data.list) ? event.data.list.map(normalizeProduct).filter((product) => product.id != null) : [];
      productsFailed = Boolean(event.data.error);
      renderCart();
    }

    if (event.data?.type === 'tariffs') {
      renderTariffs(Array.isArray(event.data.list) ? event.data.list : []);
      if (event.data.error) {
        if (event.data.message) setServerStatus(tariffPageStatus, event.data.message);
        else setStatus(tariffPageStatus, 'Не удалось загрузить тарифы. Проверьте подключение.');
      }
    }

    if (event.data?.type === 'purchaseResult') {
      tariffCatalog.querySelectorAll('.tariff-purchase').forEach((item) => {
        item.disabled = false;
      });
      if (event.data.message) setServerStatus(tariffPageStatus, event.data.message);
      else setStatus(tariffPageStatus, event.data.success ? 'Тариф подключён.' : 'Не удалось оформить тариф.');
      // Сессию после покупки присылает агент (sessionSync); здесь — только баланс.
      if (event.data.success) applyBalances(event.data.newBalance, event.data.newBonus);
    }

    // Сессия по данным сервера: после покупки, после перезапуска агента, при продлении.
    if (event.data?.type === 'sessionSync') {
      if (Number.isFinite(event.data.clockOffsetMs)) clockOffset = event.data.clockOffsetMs;
      const sessionEnd = Date.parse(event.data.sessionEndsAt || '');
      sessionEndsAt = Number.isNaN(sessionEnd) ? null : sessionEnd;
      showSessionTime();
      setSessionActive(true);
    }

    if (event.data?.type === 'sessionEnded') {
      setSessionActive(false);
      clearSessionDetails();
      setStatus(actionStatus, sessionEndMessages[event.data.reason] === undefined ? sessionEndMessages.ended : sessionEndMessages[event.data.reason]);
    }

    // Клиент вышел: на экране не остаётся его баланса и корзины.
    if (event.data?.type === 'loggedOut') {
      setSessionActive(false);
      clearSessionDetails();
      userName = null;
      showProfileName();
      applyBalances(null, null);
      cart.clear();
      renderCart();
      setStatus(actionStatus, null);
    }

    if (event.data?.type === 'actionFailed' || event.data?.type === 'error') {
      endButton.disabled = false;
      if (event.data.type === 'actionFailed' && event.data.message) setServerStatus(actionStatus, event.data.message);
      else setStatus(actionStatus, 'Не удалось выполнить действие.');
    }

    // Ошибки связи с сервером (401, 402, 403, 404) показываются пользователю без повторных запросов.
    if (event.data?.type === 'heartbeatError' && event.data.message) {
      setServerStatus(actionStatus, event.data.message);
    }

    // Название клуба и номер ПК: от агента при запуске и с каждым heartbeat.
    if (event.data?.type === 'heartbeat' && event.data.serverTime) {
      const serverTime = Date.parse(event.data.serverTime);
      if (!Number.isNaN(serverTime)) clockOffset = serverTime - Date.now();
    }

    if (event.data?.type === 'clubInfo' || event.data?.type === 'heartbeat') {
      if (event.data.clubName) document.getElementById('clubName').textContent = event.data.clubName;
      if (event.data.number) {
        const label = document.getElementById('pcLabel');
        label.dataset.number = event.data.number;
        label.dataset.zone = event.data.zone ?? '';
        showPcLabel();
      }
    }


    if (event.data?.type === 'sessionData' && event.data.error) {
      applyBalances(null, null);
      if (event.data.message) setServerStatus(actionStatus, event.data.message);
      else setStatus(actionStatus, 'Не удалось загрузить баланс.');
      return;
    }

    if (event.data?.type !== 'sessionData' || !event.data.session) return;

    const { balance, bonus, userName: name, tariff, startedAt, remainingMinutes } = event.data.session;
    applyBalances(balance ?? 0, bonus ?? 0);
    document.getElementById('profileTariff').textContent = tariff || '—';
    // Сессию ПК присылает сервер (sessionSync); профиль её не отменяет.
    const sessionActive = Number(remainingMinutes) > 0 || (sessionEndsAt !== null && sessionEndsAt > serverNow());
    setSessionActive(sessionActive);
    sessionStartedAt = startedAt && !Number.isNaN(Date.parse(startedAt)) ? Date.parse(startedAt) : null;
    showStartedAt();
    const profileRemaining = document.getElementById('profileRemaining');
    if (sessionEndsAt) showSessionTime();
    else if (Number.isFinite(Number(remainingMinutes))) {
      profileRemaining.dataset.minutes = String(Math.floor(Number(remainingMinutes)));
      profileRemaining.textContent = formatMinutes(Math.floor(Number(remainingMinutes)));
    } else {
      delete profileRemaining.dataset.minutes;
      profileRemaining.textContent = '—';
    }
    if (name) {
      userName = name;
      showProfileName();
    }
  });

  if (webview) setStatus(tariffPageStatus, 'Загружаем тарифы…');
  else setStatus(tariffPageStatus, 'Запустите приложение, чтобы загрузить тарифы.');
  webview?.postMessage({ type: 'requestI18n' });
  webview?.postMessage({ type: 'requestTariffs' });
  webview?.postMessage({ type: 'requestSession' });
  renderCart();
  fetchProducts();
  showProfileName();
})();
