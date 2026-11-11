<?php if (($area ?? 'public') === 'public'): ?>
        </main>

        <footer class="foot">
            <div class="wrap cols">
                <div>
                    <strong><?= e(SHOP_NAME) ?></strong><br>
                    <?= e(SHOP_ADDRESS) ?>
                </div>
                <div>
                    Contact<br>
                    <?= e(SHOP_PHONE) ?><br>
                    <?= e(SHOP_EMAIL) ?>
                </div>
                <div>
                    Business hours<br>
                    <?= e(SHOP_HOURS) ?>
                </div>
            </div>
        </footer>
<?php else: ?>
        </main>
    </div>
<?php endif; ?>

    <script src="<?= BASE_URL ?>assets/app.js"></script>
</body>
</html>
