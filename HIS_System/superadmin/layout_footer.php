<?php
// superadmin/layout_footer.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check session messages
$toast_msg = $_SESSION['flash_msg'] ?? '';
$modal_err = $_SESSION['flash_err'] ?? '';

// Clear them once fetched
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);
?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar   = document.getElementById('sidebarMenu');
    const overlay   = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const closeBtn  = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('show');
        if (overlay) overlay.classList.add('show');
        document.body.style.overflow = 'hidden'; // Stop background scroll on mobile
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn)  closeBtn.addEventListener('click', closeSidebar);
    if (overlay)   overlay.addEventListener('click', closeSidebar);

    // Escape key press par sidebar close karna
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('show')) {
            closeSidebar();
        }
    });
});

// Top-Right Toast Notification (Auto Dismiss 2.5s)
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 2500,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.onmouseenter = Swal.stopTimer;
        toast.onmouseleave = Swal.resumeTimer;
    }
});

function showToast(msg) {
    Toast.fire({
        icon: 'success',
        title: msg
    });
}

function showError(msg) {
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: msg,
        confirmButtonColor: '#0284c7',
        confirmButtonText: 'OK'
    });
}
</script>

<?php if (!empty($toast_msg)): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        showToast(<?= json_encode($toast_msg) ?>);
    });
</script>
<?php endif; ?>

<?php if (!empty($modal_err)): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        showError(<?= json_encode($modal_err) ?>);
    });
</script>
<?php endif; ?>

</body>
</html>