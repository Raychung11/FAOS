<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'app'): string
    {
        $file = APP_PATH . '/Views/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($layout === '') {
            return $content;
        }
        $layoutFile = APP_PATH . '/Views/layouts/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            return $content;
        }
        $title = $data['title'] ?? 'FAOS BOS';
        ob_start();
        require $layoutFile;
        return ob_get_clean();
    }
}
