<?php
// admin/layout_footer.php
?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Flatpickr: compact calendar for Doctor Desk Follow-up Date -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const sidebar = document.getElementById('sidebarMenu');
    const backdrop = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');

    /* ==========================================================
       MOBILE SIDEBAR
       ========================================================== */
    if (toggleBtn && sidebar && backdrop) {

        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });

        backdrop.addEventListener('click', function () {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        });
    }

    /* ==========================================================
       ONLY ONE MAIN MENU OPEN AT A TIME
       OPD <-> MASTERS
       Nested menus inside Masters stay independent.
       ========================================================== */
    const mainMenuCollapses = document.querySelectorAll(
        '.sidebar-menu > li > .collapse'
    );

    mainMenuCollapses.forEach(function (collapseEl) {
        collapseEl.addEventListener('show.bs.collapse', function () {

            mainMenuCollapses.forEach(function (otherEl) {
                if (
                    otherEl !== collapseEl &&
                    otherEl.classList.contains('show')
                ) {
                    bootstrap.Collapse
                        .getOrCreateInstance(otherEl, { toggle: false })
                        .hide();
                }
            });
        });
    });

    /* ==========================================================
       PRESCRIPTION MASTERS SIDEBAR SCROLL POSITION
       ========================================================== */
    const sidebarMenu = document.querySelector('.sidebar-menu');

    const prescriptionPages = [
        'master_units.php',
        'master_meals.php',
        'master_durations.php',
        'master_medicines.php',
        'master_routes.php',
        'master_frequencies.php',
        'master_timings.php',
        'master_prescription_instructions.php',
        'master_symptoms.php',
        'master_diagnoses.php',
        'prescription_template_master.php'
    ];

    const currentPage = window.location.pathname.split('/').pop();
    const isPrescriptionPage = prescriptionPages.includes(currentPage);
    const scrollStorageKey = 'prescription_sidebar_scroll_top';

    if (sidebarMenu) {

        if (isPrescriptionPage) {

            const savedScroll = sessionStorage.getItem(scrollStorageKey);

            if (savedScroll !== null) {
                const restoreScroll = function () {
                    sidebarMenu.scrollTop = parseInt(savedScroll, 10) || 0;
                };

                restoreScroll();
                setTimeout(restoreScroll, 50);
                setTimeout(restoreScroll, 200);
            }

            document.querySelectorAll(
                '.nested-submenu-items a'
            ).forEach(function (link) {

                link.addEventListener('click', function () {
                    sessionStorage.setItem(
                        scrollStorageKey,
                        String(sidebarMenu.scrollTop)
                    );
                });
            });

            sidebarMenu.addEventListener('scroll', function () {
                sessionStorage.setItem(
                    scrollStorageKey,
                    String(sidebarMenu.scrollTop)
                );
            }, { passive: true });

        } else {
            sessionStorage.removeItem(scrollStorageKey);
        }
    }

    /* ==========================================================
       DOCTOR DESK - FOLLOW-UP DATE CALENDAR
       Format: DD-MM-YYYY
       ========================================================== */
    const followUpInput = document.getElementById('follow_up_date');
    const calendarBtn = document.getElementById('followUpCalendarBtn');

    if (followUpInput && typeof flatpickr !== 'undefined') {

        const followUpPicker = flatpickr(followUpInput, {
            dateFormat: 'd-m-Y',
            allowInput: true,
            disableMobile: true,
            clickOpens: true
        });

        if (calendarBtn) {
            calendarBtn.addEventListener('click', function () {
                followUpPicker.open();
            });
        }
    }
});
</script>

</body>
</html>
