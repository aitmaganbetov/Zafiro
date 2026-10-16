{{-- Боковое меню: свернуть/развернуть, мобильная шторка. Общее для панели клуба и кабинета суперадмина. --}}
<script>
    (()=>{
        const app=document.querySelector('.admin-app'),sidebar=document.getElementById('adminSidebar'),overlay=document.getElementById('overlay'),toggle=document.getElementById('sidebarToggle');
        if(!app||!sidebar||!toggle)return;
        const mobile=matchMedia('(max-width: 768px)'),key='zafiro.sidebarCollapsed';
        const sync=()=>{
            const rail=!mobile.matches&&app.classList.contains('sidebar-collapsed');
            const open=mobile.matches?sidebar.classList.contains('active'):!rail;
            toggle.setAttribute('aria-expanded',String(open));
            toggle.setAttribute('aria-label',mobile.matches?(open?@json(__('Скрыть меню')):@json(__('Показать меню'))):(rail?@json(__('Развернуть меню')):@json(__('Свернуть меню'))));
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
