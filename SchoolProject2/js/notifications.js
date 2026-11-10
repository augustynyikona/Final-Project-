document.addEventListener('DOMContentLoaded', function() {
    // Find all notification bell icons
    const bellIcons = document.querySelectorAll('.fa-bell');
    
    bellIcons.forEach(bell => {
        let parent = bell.parentElement;
        
        // Wrap bell in container if not wrapped
        if (!parent.classList.contains('notif-wrapper')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'notif-wrapper';
            parent.insertBefore(wrapper, bell);
            wrapper.appendChild(bell);

            // Create Badge Counter Dot
            const badge = document.createElement('span');
            badge.className = 'notif-bell-badge';
            badge.style.display = 'none';
            wrapper.appendChild(badge);

            // Create Notification Drawer Box
            const dropdown = document.createElement('div');
            dropdown.className = 'notif-dropdown';
            dropdown.innerHTML = `
                <div class="notif-header">
                    <span style="font-size:11px; font-weight:600; color:#64748b;" id="notifHeaderCount"></span>
                </div>
                <div id="notifItemsList" style="max-height:340px; overflow-y:auto;">
                    <!-- Updates will load here -->
                </div>
            `;
            wrapper.appendChild(dropdown);

            // Toggle Dropdown on Bell Click
            bell.style.cursor = 'pointer';
            bell.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('show');
            });

            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });

            // Fetch live notifications
            function loadNotifications() {
                fetch('../api/get_notifications.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data && !data.error) {
                            const count = data.unread_count || (data.unread_messages + data.pending_alerts);
                            const countHeader = document.getElementById('notifHeaderCount');
                            
                            if (count > 0) {
                                badge.style.display = 'inline-block';
                                badge.innerText = count > 99 ? '99+' : count;
                                if (countHeader) countHeader.innerText = count + ' Unread';
                            } else {
                                badge.style.display = 'none';
                                if (countHeader) countHeader.innerText = '';
                            }

                            const list = document.getElementById('notifItemsList');
                            if (data.items && data.items.length > 0) {
                                list.innerHTML = data.items.map(item => `
                                    <a href="${item.link}" class="notif-item" style="padding:12px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; gap:12px; text-decoration:none; color:inherit; transition:background 0.15s;">
                                        <div style="width:32px; height:32px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                            <i class="fa-solid ${item.icon || 'fa-bell'}"></i>
                                        </div>
                                        <div style="flex:1;">
                                            <div style="font-size:12.5px; font-weight:700; color:#0f172a;">${item.title}</div>
                                            <div style="font-size:11.5px; color:#64748b; margin-top:2px; line-height:1.4;">${item.text}</div>
                                            <span style="font-size:10.5px; color:#94a3b8; margin-top:4px; display:block;">${item.time}</span>
                                        </div>
                                    </a>
                                `).join('');
                            } else {
                                list.innerHTML = `
                                    <div style="padding:32px 20px; text-align:center;">
                                        <i class="fa-regular fa-bell-slash" style="font-size:24px; color:#94a3b8; margin-bottom:8px; display:block;"></i>
                                        <div style="font-size:13px; font-weight:700; color:#0f172a;">No new notifications</div>
                                        <div style="font-size:11px; color:#94a3b8; margin-top:2px;">You're all caught up with your updates</div>
                                    </div>
                                `;
                            }
                        }
                    })
                    .catch(err => console.error("Notification fetch error:", err));
            }

            loadNotifications();
            setInterval(loadNotifications, 15000);
        }
    });
});

// ==========================================
// GLOBAL MOBILE RESPONSIVENESS INJECTION
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    // 1. Setup Mobile Hamburger Menu
    const topbarLeft = document.querySelector('.topbar-left');
    if (topbarLeft && window.innerWidth <= 768) {
        // Create hamburger button
        const menuBtn = document.createElement('button');
        menuBtn.className = 'mobile-menu-btn';
        menuBtn.innerHTML = '<i class="fa-solid fa-bars"></i>';
        
        // Insert it as the first element in topbar-left
        topbarLeft.insertBefore(menuBtn, topbarLeft.firstChild);
        
        // 2. Setup Backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'mobile-sidebar-backdrop';
        document.body.appendChild(backdrop);
        
        const sidebar = document.querySelector('.sidebar');
        
        // 3. Toggle Logic
        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (sidebar) sidebar.classList.add('open');
            backdrop.classList.add('show');
        });
        
        // Close sidebar when clicking backdrop
        backdrop.addEventListener('click', function() {
            if (sidebar) sidebar.classList.remove('open');
            backdrop.classList.remove('show');
        });
    }
});
