    </main>
    <footer class="bg-light py-3 mt-5 border-top">
        <div class="container text-center text-muted small">
            &copy; <?= date('Y') ?> Final Year Project Vault &amp; Collaboration Hub
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= base_url('assets/js/app.js') ?>"></script>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
