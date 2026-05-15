<?php
/**
 * AdvisorOS — Authenticated layout: closing shell.
 */
declare(strict_types=1);
?>
    </main>
    <footer style="padding:16px 26px;color:#9aa4b4;font-size:12px;border-top:1px solid var(--line);background:#fff">
      <?= e(APP_NAME) ?> — The Client Servicing Operating System for Financial Advisors.
      &copy; <?= date('Y') ?>. This platform supports servicing workflows and does
      not replace licensed financial advisors.
    </footer>
  </div>
</div>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php clear_old(); ?>
