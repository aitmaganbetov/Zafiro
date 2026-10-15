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

  // Локальная заглушка: без сети картинка не превращается в «битую».
  const placeholderImage = 'product-placeholder.svg';
  const normalizeProduct = (product) => ({
    id: product.id ?? product.sku,
    name: product.name ?? null,
    description: product.description ?? product.category ?? product.sku ?? null,
    // Категория склада клуба как есть (данные клуба, не переводятся).
    category: typeof product.category === 'string' && product.category.trim() ? product.category.trim() : null,
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
  const friendsPage = document.getElementById('friendsPage');
  const tariffCatalog = document.getElementById('tariffCatalog');
  const tariffPageStatus = document.getElementById('tariffPageStatus');
  const pageToggle = document.getElementById('pageToggle');
  const homeNavigation = document.getElementById('homeNavigation');
  const shopNavigation = document.getElementById('shopNavigation');
  const historyNavigation = document.getElementById('historyNavigation');
  const tariffNavigation = document.getElementById('tariffNavigation');
  const profileNavigation = document.getElementById('profileNavigation');
  const friendsNavigation = document.getElementById('friendsNavigation');
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
    const showFriends = view === 'friends';
    sessionPage.hidden = showShop || showHistory || showTariffs || showProfile || showFriends;
    friendsPage.hidden = !showFriends;
    friendsNavigation.setAttribute('aria-current', showFriends ? 'page' : 'false');
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

  // Кнопки категорий: «Все товары» и категории, которые есть у товаров клуба.
  const renderCategories = () => {
    const box = document.getElementById('shopCategories');
    const names = [...new Set(products.map((product) => product.category).filter(Boolean))]
      .sort((a, b) => a.localeCompare(b, intlLocale()));
    if (activeCategory !== 'all' && !names.includes(activeCategory)) activeCategory = 'all';
    const all = box.querySelector('[data-category="all"]');
    box.replaceChildren(all, ...names.map((name) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'category-button';
      button.dataset.category = name;
      button.textContent = name;
      return button;
    }));
    box.querySelectorAll('.category-button').forEach((item) => {
      const isActive = item.dataset.category === activeCategory;
      item.classList.toggle('is-active', isActive);
      item.setAttribute('aria-pressed', String(isActive));
    });
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
  document.getElementById('btnExtend')?.addEventListener('click', () => {
    setView('tariffs');
    requestMinutes();
  });
  document.getElementById('btnTopUp')?.addEventListener('click', () => {
    setStatus(actionStatus, 'Пополнить баланс можно на кассе клуба.');
  });
  // «Обновить»: баланс с сервера сразу, не дожидаясь автообновления (например, после пополнения на кассе).
  const refreshBalanceButton = document.getElementById('btnRefreshBalance');
  let refreshBalanceTimer = null;
  const finishBalanceRefresh = () => {
    clearTimeout(refreshBalanceTimer);
    refreshBalanceButton.disabled = false;
    refreshBalanceButton.removeAttribute('aria-busy');
  };
  refreshBalanceButton?.addEventListener('click', () => {
    if (!webview) return;
    refreshBalanceButton.disabled = true;
    refreshBalanceButton.setAttribute('aria-busy', 'true');
    refreshBalanceTimer = setTimeout(finishBalanceRefresh, 15000);
    webview.postMessage({ type: 'refreshBalance' });
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

  // После конца сессии клуб может перезагрузить или выключить ПК: клиент видит отсчёт, пока не выберет тариф.
  const afterSessionMessages = {
    restart: ['Если не выбрать тариф, ПК перезагрузится через :time.', 'ПК перезагружается…'],
    shutdown: ['Если не выбрать тариф, ПК выключится через :time.', 'ПК выключается…']
  };
  let afterSessionTimer = null;
  const stopAfterSessionCountdown = () => {
    if (afterSessionTimer === null) return;
    clearInterval(afterSessionTimer);
    afterSessionTimer = null;
    setStatus(actionStatus, null);
  };
  const startAfterSessionCountdown = (action, seconds) => {
    const messages = afterSessionMessages[action];
    if (!messages) return;
    clearInterval(afterSessionTimer);
    const deadline = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    const tick = () => {
      const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      const time = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
      setStatus(actionStatus, left > 0 ? messages[0] : messages[1], { time });
    };
    tick();
    afterSessionTimer = setInterval(tick, 1000);
  };

  const sessionEndMessages = {
    expired: 'Время сессии закончилось. Выберите тариф, чтобы продолжить.',
    server: 'Сессия завершена в клубе.',
    ended: 'Сессия завершена.',
    none: null
  };

  const showProfileStatus = () => {
    document.getElementById('profileStatus').textContent = hasActiveSession ? t('Активная сессия') : t('Нет активной сессии');
  };

  // Друзья, переводы и пересадка: только то, что клуб включил в настройках «Гости» (приходит с heartbeat).
  let guestFeatures = { friends: false, balance_transfer: false, self_transfer: false };
  let friendsData = { friends: [], incoming: [], outgoing: [] };
  const friendsBadge = document.getElementById('friendsBadge');
  const friendAddForm = document.getElementById('friendAddForm');
  const friendPhone = document.getElementById('friendPhone');
  const friendsStatus = document.getElementById('friendsStatus');
  const friendGroups = document.getElementById('friendGroups');
  const transferDialog = document.getElementById('transferDialog');
  const transferAmount = document.getElementById('transferAmount');
  const transferStatus = document.getElementById('transferStatus');
  const transferSubmit = document.getElementById('transferSubmit');
  const seatButton = document.getElementById('btnSeat');
  const seatDialog = document.getElementById('seatDialog');
  const seatStatus = document.getElementById('seatStatus');
  let transferFriend = null;
  let chosenSeat = null;
  let seatError = null; // ошибка пересадки остаётся видна после обновления карты

  const openDialog = (dialog) => {
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  };
  const closeDialog = (dialog) => {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  };
  [[transferDialog, 'transferClose'], [seatDialog, 'seatClose']].forEach(([dialog, closeId]) => {
    document.getElementById(closeId).addEventListener('click', () => closeDialog(dialog));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
    });
  });

  const updateSeatButton = () => {
    seatButton.hidden = !(guestFeatures.self_transfer && hasActiveSession);
  };

  const friendRow = (friend, ...buttons) => {
    const row = document.createElement('div');
    row.className = 'friend-row';
    const who = document.createElement('div');
    who.className = 'friend-who';
    const name = document.createElement('strong');
    name.textContent = friend.name ?? '—';
    const phone = document.createElement('small');
    phone.textContent = friend.phone ?? '';
    who.append(name, phone);
    const actions = document.createElement('div');
    actions.className = 'friend-actions';
    actions.append(...buttons);
    row.append(who, actions);
    return row;
  };
  const friendButton = (label, onClick, className = 'friend-button') => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = t(label);
    button.addEventListener('click', onClick);
    return button;
  };
  const friendGroup = (title, rows) => {
    if (rows.length === 0) return null;
    const group = document.createElement('div');
    group.className = 'friend-group';
    const heading = document.createElement('h3');
    heading.textContent = t(title);
    group.append(heading, ...rows);
    return group;
  };
  const sendFriendAction = (type, id) => {
    setStatus(friendsStatus, 'Отправляем…');
    webview?.postMessage({ type, id });
  };

  const renderFriends = () => {
    const friendsOn = guestFeatures.friends || guestFeatures.balance_transfer;
    friendsNavigation.hidden = !friendsOn;
    if (!friendsOn && currentView === 'friends') setView('session');
    friendAddForm.hidden = !guestFeatures.friends;
    // Новые заявки видны на пункте меню, даже когда страница друзей закрыта.
    const requests = guestFeatures.friends ? friendsData.incoming.length : 0;
    friendsBadge.hidden = requests === 0;
    friendsBadge.textContent = requests > 9 ? '9+' : String(requests);
    friendsNavigation.setAttribute('aria-label', requests ? t('Друзья: новых заявок — :count', { count: requests }) : t('Друзья'));
    const incoming = guestFeatures.friends
      ? friendsData.incoming.map((friend) => friendRow(friend,
        friendButton('Принять', () => sendFriendAction('acceptFriend', friend.id), 'friend-button accept'),
        friendButton('Отклонить', () => sendFriendAction('removeFriend', friend.id))))
      : [];
    const friends = friendsData.friends.map((friend) => friendRow(friend,
      ...(guestFeatures.balance_transfer ? [friendButton('Перевести', () => {
        transferFriend = friend;
        document.getElementById('transferFriend').textContent = `${friend.name ?? ''} · ${friend.phone ?? ''}`;
        transferAmount.value = '';
        setStatus(transferStatus, null);
        transferSubmit.disabled = false;
        openDialog(transferDialog);
        transferAmount.focus();
      }, 'friend-button accept')] : []),
      friendButton('Удалить', () => sendFriendAction('removeFriend', friend.id))));
    const outgoing = friendsData.outgoing.map((friend) => friendRow(friend,
      friendButton('Отменить заявку', () => sendFriendAction('removeFriend', friend.id))));
    const groups = [
      friendGroup('Заявки в друзья', incoming),
      friendGroup('Мои друзья', friends),
      friendGroup('Отправленные заявки', outgoing)
    ].filter(Boolean);
    if (groups.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'friend-empty';
      empty.textContent = t('Пока нет друзей.');
      groups.push(empty);
    }
    friendGroups.replaceChildren(...groups);
  };

  const requestFriends = () => {
    if (!webview || !(guestFeatures.friends || guestFeatures.balance_transfer)) return;
    webview.postMessage({ type: 'requestFriends' });
  };

  friendAddForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const phone = friendPhone.value.replace(/\D+/g, '');
    if (phone.length < 10) {
      setStatus(friendsStatus, 'Введите 10 цифр номера после +7.');
      return;
    }
    setStatus(friendsStatus, 'Отправляем…');
    webview?.postMessage({ type: 'addFriend', phone });
  });

  document.getElementById('transferForm').addEventListener('submit', (event) => {
    event.preventDefault();
    const amount = transferAmount.value.trim().replace(',', '.');
    if (!transferFriend || !/^\d{1,10}(\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) {
      setStatus(transferStatus, 'Введите сумму, например 500 или 250.50.');
      return;
    }
    transferSubmit.disabled = true;
    setStatus(transferStatus, 'Переводим…');
    webview?.postMessage({ type: 'transferBalance', id: transferFriend.id, amount });
  });

  // Карта для пересадки — те же зоны и места ПК, что на карте клуба в панели (координаты оттуда же).
  const seatMap = document.getElementById('seatMap');
  const seatMapFrame = document.getElementById('seatMapFrame');
  const seatMapScroll = document.getElementById('seatMapScroll');
  const seatConfirm = document.getElementById('seatConfirm');
  const ZONE_COMPUTERS_LEFT = 12;
  const ZONE_COMPUTERS_TOP = 106;
  let seatPlan = { width: 0, height: 0 };

  const fitSeatMap = () => {
    if (!seatPlan.width) return;
    const scale = Math.min(1, (seatMapScroll.clientWidth - 2) / seatPlan.width);
    seatMap.style.transform = `scale(${scale})`;
    seatMapFrame.style.width = `${Math.ceil(seatPlan.width * scale)}px`;
    seatMapFrame.style.height = `${Math.ceil(seatPlan.height * scale)}px`;
  };
  window.addEventListener('resize', fitSeatMap);

  const showChosenSeat = (name = null) => {
    seatConfirm.disabled = chosenSeat === null;
    seatConfirm.textContent = chosenSeat === null ? t('Выберите свободный ПК') : t('Пересесть на ПК :name', { name });
  };

  const renderSeats = (map) => {
    chosenSeat = null;
    showChosenSeat();
    const zones = Array.isArray(map?.zones) ? map.zones : [];
    const computers = Array.isArray(map?.computers) ? map.computers : [];
    const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
    // Пустое поле слева и сверху не показываем: карта занимает всё окно.
    const left = zones.length ? Math.max(0, Math.min(...zones.map((zone) => number(zone.x))) - 20) : 0;
    const top = zones.length ? Math.max(0, Math.min(...zones.map((zone) => number(zone.y))) - 20) : 0;
    seatPlan = {
      width: Math.max(320, ...zones.map((zone) => number(zone.x) - left + number(zone.width) + 20)),
      height: Math.max(200, ...zones.map((zone) => number(zone.y) - top + number(zone.height) + 20))
    };
    seatMap.style.width = `${seatPlan.width}px`;
    seatMap.style.height = `${seatPlan.height}px`;

    seatMap.replaceChildren(...zones.map((zone) => {
      const box = document.createElement('section');
      box.className = `seat-zone ${typeof zone.theme === 'string' ? zone.theme : ''}`;
      Object.assign(box.style, { left: `${number(zone.x) - left}px`, top: `${number(zone.y) - top}px`, width: `${number(zone.width)}px`, height: `${number(zone.height)}px` });
      const title = document.createElement('h3');
      title.textContent = zone.name ?? '';
      const places = document.createElement('div');
      places.className = 'seat-zone-computers';
      places.style.left = `${ZONE_COMPUTERS_LEFT}px`;
      places.style.top = `${ZONE_COMPUTERS_TOP}px`;
      computers.filter((computer) => computer.zone_id === zone.id).forEach((computer) => {
        const current = computer.id === map.current_computer_id;
        const pc = document.createElement('button');
        pc.type = 'button';
        pc.className = `seat-pc ${current ? 'current' : computer.available ? 'free' : 'busy'}`;
        pc.disabled = !computer.available;
        pc.textContent = String(computer.number ?? '');
        pc.title = current ? `${computer.name ?? ''} · ${t('Вы здесь')}` : computer.name ?? '';
        pc.setAttribute('aria-label', pc.title);
        Object.assign(pc.style, { left: `${number(computer.x)}px`, top: `${number(computer.y)}px` });
        if (computer.available) {
          pc.addEventListener('click', () => {
            chosenSeat = computer.id;
            seatMap.querySelectorAll('.seat-pc').forEach((item) => item.classList.toggle('is-chosen', item === pc));
            showChosenSeat(computer.name ?? computer.number);
          });
        }
        places.append(pc);
      });
      box.append(title, places);
      return box;
    }));

    setStatus(seatStatus, computers.some((computer) => computer.available) ? 'Выберите свободный ПК на карте.' : 'Свободных ПК, где действует ваш тариф, сейчас нет.');
    fitSeatMap();
  };

  seatConfirm.addEventListener('click', () => {
    if (chosenSeat === null || !webview) return;
    seatConfirm.disabled = true;
    seatMap.querySelectorAll('.seat-pc').forEach((item) => { item.disabled = true; });
    setStatus(seatStatus, 'Переносим сессию…');
    webview.postMessage({ type: 'transferSeat', computerId: chosenSeat });
  });

  seatButton.addEventListener('click', () => {
    if (!webview) return;
    seatMap.replaceChildren();
    chosenSeat = null;
    showChosenSeat();
    setStatus(seatStatus, 'Ищем свободные ПК…');
    openDialog(seatDialog);
    webview.postMessage({ type: 'requestSeats' });
  });

  // Логи клиента: покупки, пополнения, переводы, пересадки и входы (сервер, страницами по 30).
  const historyList = document.getElementById('historyList');
  const historyEmpty = document.getElementById('historyEmpty');
  const historyStatus = document.getElementById('historyStatus');
  const historyMore = document.getElementById('historyMore');
  let historyEntries = [];
  let historyBefore = null;
  let historyLoading = false;

  const historyAmount = (value, bonus) => {
    if (value === null || value === undefined) return null;
    const amount = Number(value);
    const text = `${amount > 0 ? '+' : '−'}${Math.abs(amount).toLocaleString(intlLocale(), { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
    const element = document.createElement('span');
    element.className = `history-amount ${amount > 0 ? 'plus' : 'minus'}${bonus ? ' bonus' : ''}`;
    element.textContent = bonus ? t(':amount бонусов', { amount: text }) : text;
    return element;
  };

  const renderHistory = () => {
    historyList.replaceChildren(...historyEntries.map((entry) => {
      const item = document.createElement('li');
      item.className = `history-item ${typeof entry.kind === 'string' ? entry.kind : ''}`;
      const main = document.createElement('div');
      main.className = 'history-item-main';
      const title = document.createElement('strong');
      title.textContent = entry.title ?? '';
      const meta = document.createElement('small');
      meta.textContent = [entry.at ? formatDateTime(entry.at) : null, entry.note].filter(Boolean).join(' · ');
      main.append(title, meta);
      const amounts = document.createElement('div');
      amounts.className = 'history-item-amounts';
      amounts.append(...[historyAmount(entry.amount, false), historyAmount(entry.bonus_amount, true)].filter(Boolean));
      item.append(main, amounts);
      return item;
    }));
    historyEmpty.hidden = historyEntries.length > 0 || historyLoading;
    historyMore.hidden = historyBefore === null;
    historyMore.disabled = historyLoading;
  };

  const requestHistory = (more = false) => {
    if (!webview || historyLoading) return;
    if (!more) {
      historyEntries = [];
      historyBefore = null;
    }
    historyLoading = true;
    setStatus(historyStatus, 'Загружаем операции…');
    renderHistory();
    webview.postMessage({ type: 'requestHistory', before: more ? historyBefore : null });
  };
  historyMore.addEventListener('click', () => requestHistory(true));

  // Поминутно: цена часа зоны этого ПК / 60 × минуты (округление как на сервере, до тиына).
  const minuteCard = document.getElementById('minuteCard');
  const minuteInput = document.getElementById('minuteInput');
  const minuteBuy = document.getElementById('minuteBuy');
  let minuteOffer = null;

  const minuteCount = () => {
    const value = Number(minuteInput.value);
    const max = Number(minuteOffer?.max_minutes) || 720;
    return Number.isInteger(value) && value >= 1 && value <= max ? value : null;
  };
  const renderMinutes = () => {
    minuteCard.hidden = !minuteOffer?.available;
    if (!minuteOffer?.available) return;
    const hourCents = Math.round(Number(minuteOffer.price_per_hour) * 100);
    const currency = minuteOffer.currency ?? 'KZT';
    minuteInput.max = String(Number(minuteOffer.max_minutes) || 720);
    document.getElementById('minuteRate').textContent = t('Зона :zone · :price за час', { zone: minuteOffer.zone ?? '', price: formatPrice(hourCents / 100, currency) });
    const minutes = minuteCount();
    document.getElementById('minutePrice').textContent = minutes === null
      ? t('Введите от 1 до :max минут', { max: minuteInput.max })
      : t(':minutes мин — :price', { minutes, price: formatPrice(Math.floor((hourCents * minutes + 30) / 60) / 100, currency) });
    minuteBuy.disabled = minutes === null;
  };
  const requestMinutes = () => webview?.postMessage({ type: 'requestMinutes' });

  minuteInput.addEventListener('input', renderMinutes);
  minuteCard.querySelectorAll('[data-minutes]').forEach((button) => button.addEventListener('click', () => {
    minuteInput.value = button.dataset.minutes;
    renderMinutes();
  }));
  minuteBuy.addEventListener('click', () => {
    const minutes = minuteCount();
    if (minutes === null || !webview) return;
    minuteBuy.disabled = true;
    tariffCatalog.querySelectorAll('.tariff-purchase').forEach((item) => { item.disabled = true; });
    setStatus(tariffPageStatus, 'Оформляем тариф…');
    webview.postMessage({ type: 'purchaseMinutes', minutes });
  });

  // Новости на главной: Zafiro (первая карточка) и клуб (вторая). Открываются здесь же, в окне агента.
  const newsDialog = document.getElementById('newsDialog');
  const newsCards = Array.from(document.querySelectorAll('[data-news-column]'));
  let news = { platform: [], club: [] };
  let newsOpen = { column: 'platform', index: 0 };
  const newsKickers = { platform: 'Новости Zafiro', club: 'Новости клуба' };

  const renderNewsCards = () => {
    newsCards.forEach((card) => {
      const latest = news[card.dataset.newsColumn]?.[0];
      const image = card.querySelector('img');
      const text = card.querySelector('.news-card-text');
      card.disabled = !latest;
      text.hidden = !latest;
      text.querySelector('strong').textContent = latest?.title ?? '';
      // Без картинки у новости остаётся встроенная картинка карточки.
      const src = latest?.image_url || image.dataset.fallback;
      if (image.getAttribute('src') !== src) image.setAttribute('src', src);
      card.setAttribute('aria-label', latest ? `${t(newsKickers[card.dataset.newsColumn])}: ${latest.title}` : t(newsKickers[card.dataset.newsColumn]));
    });
  };

  const showNews = () => {
    const list = news[newsOpen.column] ?? [];
    const item = list[newsOpen.index];
    if (!item) {
      closeDialog(newsDialog);
      return;
    }
    document.getElementById('newsKicker').textContent = t(newsKickers[newsOpen.column]);
    const image = document.getElementById('newsImage');
    image.hidden = !item.image_url;
    if (item.image_url) image.src = item.image_url;
    else image.removeAttribute('src');
    document.getElementById('newsTitle').textContent = item.title ?? '';
    document.getElementById('newsDate').textContent = item.published_at ? formatDateTime(item.published_at) : '';
    document.getElementById('newsBody').textContent = item.body ?? '';
    document.getElementById('newsPosition').textContent = list.length > 1 ? `${newsOpen.index + 1} / ${list.length}` : '';
    document.getElementById('newsNewer').disabled = newsOpen.index === 0;
    document.getElementById('newsOlder').disabled = newsOpen.index >= list.length - 1;
  };

  // Картинка новости не загрузилась (нет связи) — карточка показывает свою встроенную.
  newsCards.forEach((card) => {
    const image = card.querySelector('img');
    image.addEventListener('error', () => {
      if (image.getAttribute('src') !== image.dataset.fallback) image.setAttribute('src', image.dataset.fallback);
    });
  });
  newsCards.forEach((card) => card.addEventListener('click', () => {
    if (!news[card.dataset.newsColumn]?.length) return;
    newsOpen = { column: card.dataset.newsColumn, index: 0 };
    showNews();
    openDialog(newsDialog);
  }));
  document.getElementById('newsNewer').addEventListener('click', () => {
    newsOpen.index = Math.max(0, newsOpen.index - 1);
    showNews();
  });
  document.getElementById('newsOlder').addEventListener('click', () => {
    newsOpen.index += 1;
    showNews();
  });
  document.getElementById('newsClose').addEventListener('click', () => closeDialog(newsDialog));
  newsDialog.addEventListener('click', (event) => {
    if (event.target === newsDialog) closeDialog(newsDialog);
  });
  const requestNews = () => webview?.postMessage({ type: 'requestNews' });
  setInterval(requestNews, 10 * 60 * 1000);

  const setSessionActive = (active) => {
    hasActiveSession = active;
    endButton.disabled = false;
    endButtonLabel.textContent = active ? t('Завершить сессию') : t('Выйти');
    endButtonIcon.setAttribute('d', active ? endIconPaths.end : endIconPaths.logout);
    document.getElementById('extendLabel').textContent = active ? t('Продлить сессию') : t('Выбрать тариф');
    showProfileStatus();
    updateSeatButton();
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

  homeNavigation.addEventListener('click', () => {
    setView('session');
    requestNews();
  });
  shopNavigation.addEventListener('click', () => setView('shop'));
  historyNavigation.addEventListener('click', () => setView('history'));
  tariffNavigation.addEventListener('click', () => {
    setView('tariffs');
    requestMinutes();
  });
  profileNavigation.addEventListener('click', () => setView('profile'));
  friendsNavigation.addEventListener('click', () => {
    setView('friends');
    requestFriends();
  });
  historyNavigation.addEventListener('click', () => requestHistory());
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
    renderFriends();
    renderHistory();
    renderMinutes();
    renderNewsCards();
    if (newsDialog.open) showNews();
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
      renderCategories();
      renderCart();
    }

    if (event.data?.type === 'news') {
      if (event.data.ok && event.data.data) {
        news = {
          platform: Array.isArray(event.data.data.platform) ? event.data.data.platform : [],
          club: Array.isArray(event.data.data.club) ? event.data.data.club : []
        };
        renderNewsCards();
        if (newsDialog.open) showNews();
      }
    }

    if (event.data?.type === 'minutes') {
      minuteOffer = event.data.ok && event.data.data ? event.data.data : null;
      renderMinutes();
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
      renderMinutes();
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

    // Баланс обновляется сам (раз в ~20 с): пополнение на кассе видно без повторного входа.
    if (event.data?.type === 'balance') {
      applyBalances(event.data.balance, event.data.bonus);
      if (event.data.manual) {
        finishBalanceRefresh();
        setStatus(actionStatus, 'Баланс обновлён.');
      }
    }

    if (event.data?.type === 'balanceError') {
      finishBalanceRefresh();
      setStatus(actionStatus, 'Не удалось обновить баланс. Проверьте подключение.');
    }

    if (event.data?.type === 'sessionEnded') {
      setSessionActive(false);
      clearSessionDetails();
      setStatus(actionStatus, sessionEndMessages[event.data.reason] === undefined ? sessionEndMessages.ended : sessionEndMessages[event.data.reason]);
    }

    if (event.data?.type === 'afterSession') startAfterSessionCountdown(event.data.action, event.data.seconds);
    if (event.data?.type === 'afterSessionCancelled') stopAfterSessionCountdown();

    // Клиент вышел: на экране не остаётся его баланса и корзины.
    if (event.data?.type === 'guestFeatures' && event.data.features && typeof event.data.features === 'object') {
      guestFeatures = {
        friends: event.data.features.friends === true,
        balance_transfer: event.data.features.balance_transfer === true,
        self_transfer: event.data.features.self_transfer === true
      };
      updateSeatButton();
      renderFriends();
      // С каждым heartbeat (~30 с): новые заявки в друзья видны без перезахода.
      requestFriends();
    }

    if (event.data?.type === 'friends') {
      const data = event.data.data;
      if (event.data.ok && data) {
        friendsData = {
          friends: Array.isArray(data.friends) ? data.friends : [],
          incoming: Array.isArray(data.incoming) ? data.incoming : [],
          outgoing: Array.isArray(data.outgoing) ? data.outgoing : []
        };
        if (typeof data.message === 'string') {
          setServerStatus(friendsStatus, data.message);
          friendPhone.value = '';
        } else setStatus(friendsStatus, null);
        renderFriends();
      } else setServerStatus(friendsStatus, event.data.message ?? t('Не удалось выполнить действие.'));
    }

    if (event.data?.type === 'transferResult') {
      transferSubmit.disabled = false;
      if (event.data.ok) {
        // Бонусы не переводятся: меняется только основной счёт.
        if (event.data.data?.balance !== undefined) applyBalances(event.data.data.balance, balances[1]);
        closeDialog(transferDialog);
        setServerStatus(friendsStatus, event.data.message ?? '');
      } else setServerStatus(transferStatus, event.data.message ?? t('Не удалось выполнить действие.'));
    }

    if (event.data?.type === 'history') {
      historyLoading = false;
      if (event.data.ok && Array.isArray(event.data.data?.data)) {
        historyEntries = historyEntries.concat(event.data.data.data);
        historyBefore = Number.isInteger(event.data.data.next_before) ? event.data.data.next_before : null;
        setStatus(historyStatus, null);
      } else setServerStatus(historyStatus, event.data.message ?? t('Не удалось загрузить операции.'));
      renderHistory();
    }

    if (event.data?.type === 'seats') {
      if (event.data.ok) {
        renderSeats(event.data.data);
        if (seatError) setServerStatus(seatStatus, seatError);
        seatError = null;
      }
      else setServerStatus(seatStatus, event.data.message ?? t('Не удалось выполнить действие.'));
    }

    if (event.data?.type === 'seatResult') {
      if (event.data.ok) {
        closeDialog(seatDialog);
        setServerStatus(actionStatus, event.data.message ?? '');
      } else {
        // ПК мог только что занять кто-то другой: карта загружается заново.
        seatError = event.data.message ?? t('Не удалось выполнить действие.');
        setServerStatus(seatStatus, seatError);
        webview?.postMessage({ type: 'requestSeats' });
      }
    }

    if (event.data?.type === 'loggedOut') {
      friendsData = { friends: [], incoming: [], outgoing: [] };
      renderFriends();
      historyEntries = [];
      historyBefore = null;
      renderHistory();
      closeDialog(newsDialog);
      closeDialog(transferDialog);
      closeDialog(seatDialog);
      setSessionActive(false);
      clearSessionDetails();
      userName = null;
      showProfileName();
      applyBalances(null, null);
      stopAfterSessionCountdown();
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
  requestMinutes();
  requestNews();
  webview?.postMessage({ type: 'requestSession' });
  webview?.postMessage({ type: 'requestGuestFeatures' });
  renderCart();
  fetchProducts();
  showProfileName();
})();
