<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('page-title', __('Панель управления')) · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
</head>
<body class="admin-body dark:bg-gray-950">
    <div class="overlay" id="overlay"></div>
    <div class="admin-app">
        <script>try{if(localStorage.getItem('zafiro.sidebarCollapsed')==='1'&&!matchMedia('(max-width: 768px)').matches)document.currentScript.parentElement.classList.add('sidebar-collapsed')}catch(e){}</script>
        @include('partials.sidebar')
        <main class="admin-main">
            <header class="admin-topbar">
                <div class="admin-topbar-inner">
                    <div class="admin-title-wrap"><button type="button" id="sidebarToggle" class="admin-mobile-toggle" aria-label="{{ __('Показать меню') }}" aria-controls="adminSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button><h2 class="admin-title">@yield('page-title', __('Панель управления'))</h2></div>
                    <div class="admin-topbar-actions">
                        @if(($availableClubs ?? collect())->count() > 1)
                            <form method="POST" action="{{ route('clubs.switch') }}">@csrf
                                <select name="club_id" aria-label="{{ __('Активный клуб') }}" onchange="this.form.submit()" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach($availableClubs as $membership)
                                        <option value="{{ $membership->club_id }}" @selected($currentClub?->id === $membership->club_id)>{{ $membership->club->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                        <label class="admin-search"><i class="fas fa-search"></i><input type="search" placeholder="{{ __('Поиск...') }}"></label>
                        <div class="admin-notifications" data-notifications data-url="{{ route('notifications.index') }}" data-seen-url="{{ route('notifications.seen') }}" data-csrf="{{ csrf_token() }}">
                            <button type="button" id="notifications-button" class="admin-icon-button" aria-label="{{ __('Уведомления') }}" aria-haspopup="true" aria-expanded="false" aria-controls="notifications-panel"><i class="far fa-bell"></i><span class="dot" data-notifications-dot hidden></span></button>
                            <div id="notifications-panel" class="admin-notifications-panel" role="region" aria-label="{{ __('Уведомления') }}" hidden>
                                <div class="admin-notifications-head"><strong>{{ __('Уведомления') }}</strong></div>
                                <ul class="admin-notifications-list" data-notifications-list></ul>
                                <p class="admin-notifications-empty" data-notifications-empty hidden>{{ __('Уведомлений нет') }}</p>
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
        (()=>{
            const app=document.querySelector('.admin-app'),sidebar=document.getElementById('adminSidebar'),overlay=document.getElementById('overlay'),toggle=document.getElementById('sidebarToggle');
            if(!app||!sidebar||!toggle)return;
            const mobile=matchMedia('(max-width: 768px)'),key='zafiro.sidebarCollapsed';
            const groups=[...sidebar.querySelectorAll('.sidebar-group')];
            groups.forEach((group)=>{group.dataset.initialOpen=group.open?'1':'0';});
            const sync=()=>{
                const rail=!mobile.matches&&app.classList.contains('sidebar-collapsed');
                const open=mobile.matches?sidebar.classList.contains('active'):!rail;
                toggle.setAttribute('aria-expanded',String(open));
                toggle.setAttribute('aria-label',mobile.matches?(open?@json(__('Скрыть меню')):@json(__('Показать меню'))):(rail?@json(__('Развернуть меню')):@json(__('Свернуть меню'))));
                groups.forEach((group)=>{group.open=rail||group.dataset.initialOpen==='1';});
                sidebar.querySelectorAll('a.sidebar-link').forEach((link)=>{
                    if(rail)link.title=link.textContent.trim();else link.removeAttribute('title');
                });
            };
            const closeDrawer=()=>{sidebar.classList.remove('active');overlay?.classList.remove('active');sync();};
            toggle.addEventListener('click',()=>{
                if(mobile.matches){
                    const open=!sidebar.classList.contains('active');
                    sidebar.classList.toggle('active',open);overlay?.classList.toggle('active',open);
                }else{
                    const collapsed=app.classList.toggle('sidebar-collapsed');
                    try{localStorage.setItem(key,collapsed?'1':'0');}catch(e){}
                }
                sync();
            });
            overlay?.addEventListener('click',closeDrawer);
            sidebar.querySelector('[data-sidebar-close]')?.addEventListener('click',()=>{closeDrawer();toggle.focus();});
            document.addEventListener('keydown',(event)=>{if(event.key==='Escape'&&sidebar.classList.contains('active')){closeDrawer();toggle.focus();}});
            mobile.addEventListener('change',()=>{
                closeDrawer();
                let collapsed=false;try{collapsed=localStorage.getItem(key)==='1';}catch(e){}
                app.classList.toggle('sidebar-collapsed',collapsed&&!mobile.matches);sync();
            });
            sync();
        })();
    </script>
</body>
</html>
