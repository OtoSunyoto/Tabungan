</main>
<footer class="wrap foot">Aplikasi Tabungan <strong>Muhammad Hafiz S.</strong> &copy; <?= date('Y') ?></footer>
<script>
// Format input nominal dengan pemisah ribuan (1.500.000)
document.querySelectorAll('input.rupiah').forEach(function (el) {
    function fmt() {
        var digits = el.value.replace(/\D/g, '');
        el.value = digits ? digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
    }
    el.addEventListener('input', fmt);
    fmt();
});
// Konfirmasi sebelum hapus
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
        if (!confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
});
</script>
</body>
</html>
