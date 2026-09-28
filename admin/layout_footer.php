<?php
// admin/layout_footer.php
?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (toggleBtn && sidebar && backdrop) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            backdrop.classList.toggle('show');
        });

        backdrop.addEventListener('click', () => {
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
        });
    }

    // ==========================================================
    // SIDEBAR: ONLY ONE MAIN MENU CAN STAY OPEN AT A TIME
    // Example: OPENING OPD SERVICES WILL CLOSE MASTERS.
    // Nested menus inside Masters remain independent.
    // ==========================================================
    const mainMenuCollapses = document.querySelectorAll('.sidebar-menu > li > .collapse');

    mainMenuCollapses.forEach(function(collapseEl) {
        collapseEl.addEventListener('show.bs.collapse', function() {
            mainMenuCollapses.forEach(function(otherEl) {
                if (otherEl !== collapseEl && otherEl.classList.contains('show')) {
                    bootstrap.Collapse.getOrCreateInstance(otherEl, { toggle: false }).hide();
                }
            });
        });
    });
});
</script>
</body>
</html>
