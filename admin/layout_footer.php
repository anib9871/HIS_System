<?php
// admin/layout_footer.php
?>

    </main>
</div>

<!-- Bootstrap JS: ensure it is loaded only once -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Flatpickr -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
(function () {
    'use strict';

    function initializeLayout() {

        /* =====================================================
           1. SIDEBAR: DESKTOP COLLAPSE + MOBILE DRAWER
           ===================================================== */
        const sidebar = document.getElementById('sidebarMenu');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const closeBtn = document.getElementById('sidebarCloseBtn');
        const sidebarStateKey = 'admin_sidebar_collapsed';

        // Restore the desktop sidebar state after navigating to another page.
        if (window.innerWidth >= 992 && sessionStorage.getItem(sidebarStateKey) === '1') {
            document.body.classList.add('sidebar-collapsed');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }

        function openSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('show');
            overlay.classList.add('show');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
        }

        function closeSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }

        function toggleSidebar() {
            if (!sidebar || !overlay) return;

            // Desktop: hide/show sidebar and expand/restore the main content area.
            if (window.innerWidth >= 992) {
                const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
                sessionStorage.setItem(sidebarStateKey, isCollapsed ? '1' : '0');
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(!isCollapsed));
                return;
            }

            // Mobile/tablet: keep the existing slide-in drawer behaviour.
            if (sidebar.classList.contains('show')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }

        if (toggleBtn && sidebar && overlay) {
            toggleBtn.addEventListener('click', function (event) {
                event.preventDefault();
                toggleSidebar();
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeSidebar);
        }

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && window.innerWidth < 992) {
                closeSidebar();
            }
        });

        if (sidebar) {
            sidebar.querySelectorAll('a[href]').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth < 992) closeSidebar();
                });
            });
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992) {
                closeSidebar();
                // Keep the saved desktop collapsed/expanded state intact.
            }
        });


        /* =====================================================
           2. MAIN MENUS
           Masters <-> OPD Services <-> IPD Services
           ===================================================== */
        const mainMenuCollapses = document.querySelectorAll('.sidebar-menu > li > .collapse');
        mainMenuCollapses.forEach(function (collapseEl) {
            collapseEl.addEventListener('show.bs.collapse', function () {
                mainMenuCollapses.forEach(function (otherEl) {
                    if (otherEl !== collapseEl && otherEl.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(otherEl, { toggle: false }).hide();
                    }
                });
            });
        });


        /* =====================================================
           3. NESTED MENUS
           Prescription Masters <-> Facilities & Rooms
           ===================================================== */
        const nestedCollapses = document.querySelectorAll('#nestedPrescriptionSubmenu, #nestedFacilitySubmenu');
        nestedCollapses.forEach(function (collapseEl) {
            collapseEl.addEventListener('show.bs.collapse', function () {
                nestedCollapses.forEach(function (otherEl) {
                    if (otherEl !== collapseEl && otherEl.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(otherEl, { toggle: false }).hide();
                    }
                });
            });
        });


        /* =====================================================
           4. SIDEBAR SCROLL POSITION
           ===================================================== */
        const sidebarMenu = document.querySelector('.sidebar-menu');
        const prescriptionPages = [
            'master_units.php', 'frequency_master.php', 'master_frequency.php', 'master_meals.php',
            'master_durations.php', 'master_medicines.php', 'master_routes.php', 'master_frequencies.php',
            'master_timings.php', 'master_prescription_instructions.php', 'master_symptoms.php',
            'master_diagnoses.php', 'master_investigations.php', 'master_vitals.php', 'master_advices.php',
            'prescription_template_master.php'
        ];
        const facilityPages = [
            'master_buildings.php', 'master_blocks.php', 'master_floors.php', 'master_room_categories.php', 'master_rooms.php'
        ];
        const generalMasterPages = [
            'org_profile.php', 'master_states.php', 'master_city.php', 'master_financial_year.php',
            'master_centers.php', 'users.php', 'master_doctors.php', 'master_departments.php',
            'master_qualifications.php', 'master_specializations.php', 'master_days.php',
            'master_payment_modes.php', 'master_insurance_categories.php', 'master_doctor_services.php',
            'master_doctor_tariff.php'
        ];

        const currentPage = window.location.pathname.split('/').pop();
        const preserveSidebarScroll = prescriptionPages.includes(currentPage) || facilityPages.includes(currentPage) || generalMasterPages.includes(currentPage);
        const scrollStorageKey = 'admin_sidebar_scroll_top';

        if (sidebarMenu) {
            if (preserveSidebarScroll) {
                const savedScroll = sessionStorage.getItem(scrollStorageKey);
                if (savedScroll !== null) {
                    const restoreScroll = function () {
                        sidebarMenu.scrollTop = parseInt(savedScroll, 10) || 0;
                    };
                    restoreScroll();
                    requestAnimationFrame(restoreScroll);
                    setTimeout(restoreScroll, 150);
                    setTimeout(restoreScroll, 350);
                }

                sidebarMenu.addEventListener('scroll', function () {
                    sessionStorage.setItem(scrollStorageKey, String(sidebarMenu.scrollTop));
                }, { passive: true });

                sidebarMenu.querySelectorAll('a[href]').forEach(function (link) {
                    link.addEventListener('click', function () {
                        sessionStorage.setItem(scrollStorageKey, String(sidebarMenu.scrollTop));
                    });
                });
            } else {
                sessionStorage.removeItem(scrollStorageKey);
            }
        }


        /* =====================================================
           5. PRESERVE SCROLL WHEN NESTED MENUS EXPAND/COLLAPSE
           ===================================================== */
        ['nestedPrescriptionSubmenu', 'nestedFacilitySubmenu'].forEach(function (menuId) {
            const collapseEl = document.getElementById(menuId);
            if (!collapseEl || !sidebarMenu) return;

            let savedPosition = 0;
            collapseEl.addEventListener('show.bs.collapse', function () { savedPosition = sidebarMenu.scrollTop; });
            collapseEl.addEventListener('shown.bs.collapse', function () { sidebarMenu.scrollTop = savedPosition; });
            collapseEl.addEventListener('hide.bs.collapse', function () { savedPosition = sidebarMenu.scrollTop; });
            collapseEl.addEventListener('hidden.bs.collapse', function () { sidebarMenu.scrollTop = savedPosition; });
        });


        /* =====================================================
           6. GLOBAL CALENDAR SETTINGS (DD-MM-YYYY)
           Applies to all date inputs & the Doctor Desk
           ===================================================== */
        if (typeof flatpickr !== 'undefined') {
            // Display DD-MM-YYYY while submitting YYYY-MM-DD to PHP.
            flatpickr('input[type="date"]', {
                altInput: true,
                altFormat: 'd-m-Y',
                dateFormat: 'Y-m-d',
                disableMobile: true,
                allowInput: true
            });

            // Specific handler for the Follow-Up Date button in Doctor Desk.
            const followUpInput = document.getElementById('follow_up_date');
            const calendarBtn = document.getElementById('followUpCalendarBtn');

            if (followUpInput && followUpInput._flatpickr && calendarBtn) {
                calendarBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    followUpInput._flatpickr.open();
                });
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeLayout, { once: true });
    } else {
        initializeLayout();
    }
})();
</script>

</body>
</html>
