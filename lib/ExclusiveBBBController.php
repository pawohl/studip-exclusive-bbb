<?php

use ExclusiveBBB\Settings;

/**
 * Base controller for all ExclusiveBBB controllers.
 */
abstract class ExclusiveBBBController extends PluginController
{
    public const IMAGE_TYPES = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
    ];
    public const IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    protected function requireLogin(): User
    {
        $GLOBALS['perm']->check('user');
        return User::findCurrent();
    }

    protected function requirePost(): void
    {
        if (!Request::isPost()) {
            throw new MethodNotAllowedException();
        }
        CSRFProtection::verifyUnsafeRequest();
    }

    /**
     * Stores an uploaded image as <basename>.<ext> in the plugin upload dir.
     * SVG is not accepted since it could carry scripts.
     *
     * @return string|null stored file name, null if nothing was uploaded
     * @throws InvalidArgumentException on invalid files
     */
    protected function storeUploadedImage(string $field, string $basename): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException($this->_('Das Bild konnte nicht hochgeladen werden.'));
        }
        if ($file['size'] > self::IMAGE_MAX_BYTES) {
            throw new InvalidArgumentException($this->_('Das Bild darf höchstens 2 MB groß sein.'));
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset(self::IMAGE_TYPES[$info[2]])) {
            throw new InvalidArgumentException($this->_('Erlaubt sind nur PNG-, JPEG- und WebP-Bilder.'));
        }

        $this->deleteImage($basename);
        $name = $basename . '.' . self::IMAGE_TYPES[$info[2]];
        move_uploaded_file($file['tmp_name'], Settings::uploadPath() . '/' . $name);
        return $name;
    }

    protected function deleteImage(string $basename): void
    {
        foreach (self::IMAGE_TYPES as $extension) {
            $path = Settings::uploadPath() . '/' . $basename . '.' . $extension;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function renderImage(?string $name): void
    {
        $path = $name ? Settings::uploadPath() . '/' . basename($name) : null;
        if (!$path || !is_file($path)) {
            $this->set_status(404);
            $this->render_nothing();
            return;
        }
        $this->response->add_header('X-Content-Type-Options', 'nosniff');
        $this->response->add_header('Cache-Control', 'public, max-age=3600');
        $this->render_file($path, basename($path), mime_content_type($path), 'inline');
    }
}
