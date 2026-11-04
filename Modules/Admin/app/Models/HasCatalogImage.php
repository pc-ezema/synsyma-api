<?php

namespace Modules\Admin\Models;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by ServiceCategory and Service: one photo stored on the public disk.
 * Needs an `image_path` column.
 */
trait HasCatalogImage
{
    /** Folder inside storage/app/public. */
    abstract protected function imageFolder(): string;

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Saves the new photo first, then deletes the old one, so a failed upload never loses the old image. */
    public function replaceImage(UploadedFile $file): void
    {
        $old = $this->image_path;
        $this->image_path = $file->store($this->imageFolder(), 'public');
        $this->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }

    public function removeImage(): void
    {
        $old = $this->image_path;
        $this->image_path = null;
        $this->save();

        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }
}
