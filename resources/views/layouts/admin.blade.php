<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page-title', 'Панель управления') · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
</head>
<body class="admin-body dark:bg-gray-950">
    <div class="overlay" id="overlay"></div>
    <div class="admin-app">
        @include('partials.sidebar')
        <main class="admin-main">
            <header class="admin-topbar">
                <div class="admin-topbar-inner">
                    <div class="admin-title-wrap"><button id="sidebarToggle" class="admin-mobile-toggle" aria-label="Открыть меню"><i class="fas fa-bars"></i></button><h2 class="admin-title">@yield('page-title', 'Панель управления')</h2></div>
                    <div class="admin-topbar-actions">
                        @if(($availableClubs ?? collect())->count() > 1)
                            <form method="POST" action="{{ route('clubs.switch') }}">@csrf
                                <select name="club_id" aria-label="Активный клуб" onchange="this.form.submit()" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach($availableClubs as $membership)
                                        <option value="{{ $membership->club_id }}" @selected($currentClub?->id === $membership->club_id)>{{ $membership->club->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                        <label class="admin-search"><i class="fas fa-search"></i><input type="search" placeholder="Поиск..."></label>
                        <div class="admin-notifications" data-notifications data-url="{{ route('notifications.index') }}" data-seen-url="{{ route('notifications.seen') }}" data-csrf="{{ csrf_token() }}">
                            <button type="button" id="notifications-button" class="admin-icon-button" aria-label="Уведомления" aria-haspopup="true" aria-expanded="false" aria-controls="notifications-panel"><i class="far fa-bell"></i><span class="dot" data-notifications-dot hidden></span></button>
                            <div id="notifications-panel" class="admin-notifications-panel" role="region" aria-label="Уведомления" hidden>
                                <div class="admin-notifications-head"><strong>Уведомления</strong></div>
                                <ul class="admin-notifications-list" data-notifications-list></ul>
                                <p class="admin-notifications-empty" data-notifications-empty hidden>Уведомлений нет</p>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <div class="admin-content">@yield('content')</div>
        </main>
    </div>
    @stack('scripts')
    <script>
        (()=>{
            const root=document.querySelector('[data-notifications]');
            if(!root)return;
            const button=root.querySelector('#notifications-button'),panel=root.querySelector('#notifications-panel'),list=root.querySelector('[data-notifications-list]'),empty=root.querySelector('[data-notifications-empty]'),dot=root.querySelector('[data-notifications-dot]');
            let unread=0;
            const render=(items)=>{
                list.replaceChildren(...items.map((item)=>{
                    const li=document.createElement('li'),link=document.createElement('a'),icon=document.createElement('i'),text=document.createElement('span'),title=document.createElement('strong'),body=document.createElement('span'),time=document.createElement('small');
                    link.href=item.url;link.className='admin-notification'+(item.unread?' unread':'');
                    icon.className='fas '+item.icon;icon.setAttribute('aria-hidden','true');
                    title.textContent=item.title;body.textContent=item.body;time.textContent=item.at_human;
                    text.append(title,body,time);link.append(icon,text);li.append(link);return li;
                }));
                empty.hidden=items.length>0;
            };
            const load=()=>fetch(root.dataset.url,{headers:{Accept:'application/json'},credentials:'same-origin'})
                .then((response)=>response.ok?response.json():Promise.reject(response))
                .then((data)=>{unread=data.unread_count;dot.hidden=unread===0;render(data.items);})
                .catch(()=>{});
            const close=()=>{panel.hidden=true;button.setAttribute('aria-expanded','false');};
            button.addEventListener('click',(event)=>{
                event.stopPropagation();
                const opening=panel.hidden;
                panel.hidden=!opening;button.setAttribute('aria-expanded',String(opening));
                if(opening&&unread>0){
                    unread=0;dot.hidden=true;
                    fetch(root.dataset.seenUrl,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':root.dataset.csrf},credentials:'same-origin'}).catch(()=>{});
                }
            });
            document.addEventListener('click',(event)=>{if(!root.contains(event.target))close();});
            document.addEventListener('keydown',(event)=>{if(event.key==='Escape')close();});
            load();
        })();
    </script>
    <script>
        (()=>{const sidebar=document.getElementById('adminSidebar'),overlay=document.getElementById('overlay'),toggle=document.getElementById('sidebarToggle'),userButton=document.getElementById('userMenuBtn'),userMenu=document.getElementById('userMenu');const closeSidebar=()=>{sidebar?.classList.remove('active');overlay?.classList.remove('active')};toggle?.addEventListener('click',()=>{sidebar?.classList.toggle('active');overlay?.classList.toggle('active')});overlay?.addEventListener('click',closeSidebar);userButton?.addEventListener('click',event=>{event.stopPropagation();userMenu?.classList.toggle('active')});document.addEventListener('click',event=>{if(!event.target.closest('.admin-user'))userMenu?.classList.remove('active')});document.addEventListener('keydown',event=>{if(event.key==='Escape'){closeSidebar();userMenu?.classList.remove('active')}})})();
    </script>
</body>
</html>
