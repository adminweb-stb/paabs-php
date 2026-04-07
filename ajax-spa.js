// ajax-spa.js - Smooth SPA Navigation handler for PAABS-PHP
let isAjaxBound = false;

document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    
    const container = document.getElementById('ajax-container');
    if (container && !isAjaxBound) {
        // Event delegation pada body
        document.body.addEventListener('click', (e) => {
            const link = e.target.closest('#ajax-container a');
            // Hindari intercept link luar, file unduhan, atau form
            if (link && link.href && link.href.includes('.php') && !link.hasAttribute('target')) {
                // If it's an action link (like ?delete= or ?edit=) that navigates to a totally different mode, we CAN load it smoothly if the target page also uses #ajax-container. 
                // But for pure safety, we won't reinvent everything. This works perfectly.
                e.preventDefault();
                loadDataSmoothly(link.href);
            }
        });

        window.addEventListener('popstate', () => {
            loadDataSmoothly(window.location.href, false);
        });
        
        isAjaxBound = true;
    }
    bindSearchInput();
});

function bindSearchInput() {
    let debounceTimer;
    const searchInput = document.getElementById('searchInput');
    const searchForm  = document.getElementById('searchForm');
    const container   = document.getElementById('ajax-container');

    if (searchInput && searchForm) {
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            if(container) container.style.opacity = '0.5';
            debounceTimer = setTimeout(() => {
                const url = new URL(searchForm.action || window.location.href);
                const formData = new FormData(searchForm);
                const searchParams = new URLSearchParams(formData);
                searchParams.set('q', searchInput.value);
                loadDataSmoothly(url.pathname + '?' + searchParams.toString());
            }, 400); 
        });

        searchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            clearTimeout(debounceTimer);
            const url = new URL(searchForm.action || window.location.href);
            const formData = new FormData(searchForm);
            const searchParams = new URLSearchParams(formData);
            searchParams.set('q', searchInput.value);
            loadDataSmoothly(url.pathname + '?' + searchParams.toString());
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                clearTimeout(debounceTimer);
                const url = new URL(searchForm.action || window.location.href);
                const formData = new FormData(searchForm);
                const searchParams = new URLSearchParams(formData);
                searchParams.set('q', '');
                loadDataSmoothly(url.pathname + '?' + searchParams.toString());
            }
        });
        
        if (searchInput.value && searchInput.value.length > 0) {
            const len = searchInput.value.length;
            searchInput.focus();
            searchInput.setSelectionRange(len, len);
        }
    }
}

function loadDataSmoothly(url, push = true) {
    const container = document.getElementById('ajax-container');
    if(!container) return;

    container.style.transition = 'opacity 0.2s ease-in-out';
    container.style.opacity = '0.3';
    
    fetch(url)
        .then(res => res.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContent = doc.getElementById('ajax-container');
            
            // Periksa jika halaman target memiliki ajax-container
            if (newContent) {
                container.innerHTML = newContent.innerHTML;
                if (typeof lucide !== 'undefined') lucide.createIcons();
                if (push) history.pushState(null, '', url);
                bindSearchInput(); 
                container.style.opacity = '1';
                
                // Cek apakah ada notifikasi toast juga dari doc
                const newToast = doc.getElementById('toast');
                if (newToast) {
                   const oldToast = document.getElementById('toast');
                   if (oldToast) oldToast.remove();
                   document.body.appendChild(newToast);
                   // parse the JS script inside toast (it doesn't auto execute via innerHTML parsing well, so let's just slide it out)
                   setTimeout(() => { newToast.style.opacity = '0'; setTimeout(()=>newToast.remove(),500); }, 4500);
                }
            } else {
                // Jatuh ke navigasi native jika bukan halaman filter
                window.location.href = url;
            }
        })
        .catch(err => {
            window.location.href = url;
        });
}
