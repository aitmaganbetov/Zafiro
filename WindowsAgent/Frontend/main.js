(() => {
  let products = [];

  const normalizeCategory = (value) => {
    const category = String(value ?? '').toLowerCase();
    if (category.includes('нап')) return 'drinks';
    if (category.includes('пер') || category.includes('ед') || category.includes('пищ')) return 'food';
    if (category.includes('акс') || category.includes('тех') || category.includes('игр')) return 'gear';
    return 'food';
  };

  const normalizeProduct = (product) => ({
    id: product.id ?? product.sku ?? product.name,
    name: product.name ?? 'Товар',
    description: product.description ?? product.category ?? product.sku ?? 'Товар',
    category: normalizeCategory(product.category),
    price: Number(product.price ?? 0),
    currency: product.currency ?? 'KZT',
    quantity: Number(product.quantity ?? 0),
    inStock: Boolean(product.in_stock ?? product.inStock),
    image: product.image_url ?? product.image ?? 'https://zafiro.kz/images/product-placeholder.svg'
  });

  const fetchProducts = () => {
    document.getElementById('shopCount').textContent = 'Загружаем товары…';
    if (webview) webview.postMessage({ type: 'requestProducts' });
    else document.getElementById('shopCount').textContent = 'Запустите приложение, чтобы загрузить товары.';
  };
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
  const shopNavigation = document.querySelector('.nav-item[aria-label="Магазин"]');
  const historyNavigation = document.getElementById('historyNavigation');
  const tariffNavigation = document.getElementById('tariffNavigation');
  const profileNavigation = document.getElementById('profileNavigation');
  const cartDialog = document.getElementById('cartDialog');
  const webview = window.chrome?.webview;
  let activeCategory = 'all';
  let currentView = 'session';

  if (!shopCatalog) return;

  const formatPrice = (value) => `${new Intl.NumberFormat('ru-RU').format(value)} KZT`;

  const setView = (view) => {
    currentView = view;
    const showShop = view === 'shop';
    const showHistory = view === 'history';
    const showTariffs = view === 'tariffs';
    const showProfile = view === 'profile';
    document.body.classList.toggle('shop-open', showShop);
    document.body.classList.toggle('history-open', showHistory);
    document.body.classList.toggle('tariffs-open', showTariffs);
    document.body.classList.toggle('profile-open', showProfile);
    sessionPage.hidden = showShop || showHistory || showTariffs || showProfile;
    shopPage.hidden = !showShop;
    historyPage.hidden = !showHistory;
    tariffPage.hidden = !showTariffs;
    profilePage.hidden = !showProfile;
    pageToggle.querySelector('span').textContent = view === 'session' ? 'Магазин' : 'К сессии';
    pageToggle.setAttribute('aria-label', view === 'session' ? 'Открыть магазин' : 'Вернуться к сессии');
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
    document.getElementById('shopCount').textContent = visibleProducts.length
      ? `${visibleProducts.length} ${visibleProducts.length === 1 ? 'товар' : visibleProducts.length < 5 ? 'товара' : 'товаров'}`
      : 'Товары не загружены';

    visibleProducts.forEach((product) => {
      const card = document.createElement('article');
      card.className = 'shop-product';

      const cover = document.createElement('div');
      cover.className = 'shop-product-cover';

      const image = document.createElement('img');
      image.src = product.image;
      image.alt = product.name;
      image.loading = 'lazy';

      const details = document.createElement('div');
      details.className = 'shop-product-details';

      const name = document.createElement('h3');
      name.textContent = product.name;

      const description = document.createElement('p');
      description.textContent = product.description;

      const footer = document.createElement('div');
      footer.className = 'shop-product-footer';

      const price = document.createElement('strong');
      price.textContent = `${new Intl.NumberFormat('ru-RU').format(product.price)} ${product.currency}`;

      const quantity = cart.get(product.id) || 0;
      const addButton = createActionButton(
        quantity ? `В корзине · ${quantity}` : 'В корзину',
        'shop-buy',
        () => changeQuantity(product.id, 1),
        `Добавить ${product.name} в корзину`
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
      name.textContent = product.name;

      const price = document.createElement('span');
      price.textContent = formatPrice(product.price * quantity);
      info.append(name, price);

      const controls = document.createElement('div');
      controls.className = 'quantity-controls';
      controls.append(
        createActionButton('−', 'quantity-button', () => changeQuantity(productId, -1), `Уменьшить количество: ${product.name}`),
        Object.assign(document.createElement('span'), { textContent: String(quantity) }),
        createActionButton('+', 'quantity-button', () => changeQuantity(productId, 1), `Увеличить количество: ${product.name}`)
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
    if (value < 60 || value % 60 !== 0) return `${value} мин`;

    const hours = value / 60;
    const lastDigit = hours % 10;
    const lastTwoDigits = hours % 100;
    const unit = lastTwoDigits >= 11 && lastTwoDigits <= 14
      ? 'часов'
      : lastDigit === 1
        ? 'час'
        : lastDigit >= 2 && lastDigit <= 4
          ? 'часа'
          : 'часов';
    return `${hours} ${unit}`;
  };

  const renderTariffs = (tariffs) => {
    tariffCatalog.replaceChildren();

    if (!tariffs.length) {
      tariffPageStatus.textContent = 'Сейчас тарифы недоступны.';
      return;
    }

    tariffPageStatus.textContent = '';
    tariffs.forEach((tariff) => {
      const card = document.createElement('article');
      card.className = 'tariff-card';

      const duration = document.createElement('span');
      duration.className = 'tariff-duration';
      duration.textContent = formatTariffDuration(tariffValue(tariff, 'minutes'));

      const name = document.createElement('h2');
      name.textContent = tariffValue(tariff, 'name') || 'Игровой тариф';

      const description = document.createElement('p');
      description.textContent = tariffValue(tariff, 'description') || `${formatTariffDuration(tariffValue(tariff, 'minutes'))} игры`;

      const footer = document.createElement('div');
      footer.className = 'tariff-card-footer';

      const price = document.createElement('strong');
      price.textContent = formatPrice(tariffValue(tariff, 'price'));

      const button = document.createElement('button');
      button.className = 'tariff-purchase';
      button.type = 'button';
      button.textContent = 'Выбрать';
      button.addEventListener('click', () => {
        const tariffId = tariffValue(tariff, 'id');
        if (tariffId === undefined || !webview) {
          tariffPageStatus.textContent = 'Покупка доступна в приложении.';
          return;
        }

        tariffPageStatus.textContent = 'Оформляем тариф…';
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
  const endButton = document.getElementById('btnEnd');
  const endButtonLabel = endButton.querySelector('span');
  const endButtonIcon = document.getElementById('endIconPath');
  const actionStatus = document.getElementById('actionStatus');
  const endIconPaths = {
    end: 'M12 3v9m6.4-5.4a8 8 0 1 1-12.8 0',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9'
  };
  let hasActiveSession = false;

  const setSessionActive = (active) => {
    hasActiveSession = active;
    endButton.disabled = false;
    endButtonLabel.textContent = active ? 'Завершить сессию' : 'Выйти';
    endButtonIcon.setAttribute('d', active ? endIconPaths.end : endIconPaths.logout);
  };

  const clearSessionDetails = () => {
    document.getElementById('profileStatus').textContent = 'Нет активной сессии';
    document.getElementById('profileTariff').textContent = '—';
    document.getElementById('profileStartedAt').textContent = '—';
    document.getElementById('profileRemaining').textContent = '—';
  };

  endButton.addEventListener('click', () => {
    if (!webview) {
      actionStatus.textContent = 'Действие доступно только в приложении.';
      return;
    }

    endButton.disabled = true;
    actionStatus.textContent = hasActiveSession ? 'Завершаем сессию…' : 'Выходим…';
    // endSession не разлогинивает пользователя, logout — разлогинивает.
    webview.postMessage({ type: hasActiveSession ? 'endSession' : 'logout' });
  });

  homeNavigation.addEventListener('click', () => setView('session'));
  shopNavigation.addEventListener('click', () => setView('shop'));
  historyNavigation.addEventListener('click', () => setView('history'));
  tariffNavigation.addEventListener('click', () => setView('tariffs'));
  profileNavigation.addEventListener('click', () => setView('profile'));
  pageToggle.addEventListener('click', () => setView(currentView === 'session' ? 'shop' : 'session'));

  webview?.addEventListener('message', (event) => {
    if (event.data?.type === 'products') {
      products = Array.isArray(event.data.list) ? event.data.list.map(normalizeProduct) : [];
      renderCart();
      if (event.data.error) document.getElementById('shopCount').textContent = 'Не удалось загрузить товары. Проверьте подключение.';
    }

    if (event.data?.type === 'tariffs') {
      renderTariffs(Array.isArray(event.data.list) ? event.data.list : []);
    }

    if (event.data?.type === 'purchaseResult') {
      tariffCatalog.querySelectorAll('.tariff-purchase').forEach((item) => {
        item.disabled = false;
      });
      tariffPageStatus.textContent = event.data.message || (event.data.success ? 'Тариф подключён.' : 'Не удалось оформить тариф.');
      if (event.data.success) {
        const formattedBalance = Number(event.data.newBalance ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
        const formattedBonus = Number(event.data.newBonus ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
        document.getElementById('balanceBig').textContent = formattedBalance;
        document.getElementById('bonusSmall').textContent = formattedBonus;
        document.getElementById('historyBalance').textContent = formattedBalance;
        document.getElementById('historyBonus').textContent = formattedBonus;
      }
    }

    if (event.data?.type === 'sessionEnded') {
      setSessionActive(false);
      clearSessionDetails();
      actionStatus.textContent = 'Сессия завершена. Вы остались в системе.';
    }

    if (event.data?.type === 'loggedOut') {
      setSessionActive(false);
      clearSessionDetails();
      updateProfileName('Гость');
      actionStatus.textContent = '';
    }

    if (event.data?.type === 'actionFailed') {
      endButton.disabled = false;
      actionStatus.textContent = event.data.message || 'Не удалось выполнить действие.';
    }

    // Ошибки связи с сервером (401, 402, 403, 404) показываются пользователю без повторных запросов.
    if (event.data?.type === 'heartbeatError' && event.data.message) {
      actionStatus.textContent = event.data.message;
    }

    if (event.data?.type === 'authSuccess' && event.data.userName) {
      updateProfileName(event.data.userName);
      document.getElementById('profileStatus').textContent = 'Авторизован';
    }

    if (event.data?.type === 'sessionData' && event.data.error) {
      ['balanceBig', 'historyBalance', 'profileBalance'].forEach((id) => {
        document.getElementById(id).textContent = '—';
      });
      actionStatus.textContent = event.data.message || 'Не удалось загрузить баланс.';
      return;
    }

    if (event.data?.type !== 'sessionData' || !event.data.session) return;

    const { balance, bonus, userName, tariff, startedAt, remainingMinutes } = event.data.session;
    const formattedBalance = Number(balance ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
    const formattedBonus = Number(bonus ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
    document.getElementById('balanceBig').textContent = formattedBalance;
    document.getElementById('bonusSmall').textContent = formattedBonus;
    document.getElementById('historyBalance').textContent = formattedBalance;
    document.getElementById('historyBonus').textContent = formattedBonus;
    document.getElementById('profileBalance').textContent = formattedBalance;
    document.getElementById('profileBonus').textContent = formattedBonus;
    document.getElementById('profileTariff').textContent = tariff || '—';
    const sessionActive = Number(remainingMinutes) > 0;
    setSessionActive(sessionActive);
    document.getElementById('profileStatus').textContent = sessionActive ? 'Активная сессия' : 'Нет активной сессии';
    document.getElementById('profileStartedAt').textContent = startedAt && !Number.isNaN(Date.parse(startedAt))
      ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(startedAt))
      : '—';
    document.getElementById('profileRemaining').textContent = Number.isFinite(Number(remainingMinutes))
      ? `${Math.max(0, Math.floor(Number(remainingMinutes)))} мин`
      : '—';
    if (userName) updateProfileName(userName);
  });

  tariffPageStatus.textContent = webview ? 'Загружаем тарифы…' : 'Запустите приложение, чтобы загрузить тарифы.';
  webview?.postMessage({ type: 'requestTariffs' });
  webview?.postMessage({ type: 'requestSession' });
  renderCart();
  fetchProducts();

  function updateProfileName(name) {
    document.getElementById('userName').textContent = name;
    document.getElementById('profileDisplayName').textContent = name;
    document.getElementById('profileAvatarText').textContent = name.trim().charAt(0).toLocaleUpperCase('ru-RU') || 'Г';
  }
})();
