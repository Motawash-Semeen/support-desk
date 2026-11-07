<?php

namespace App\Modules\Conversation\Http\Resources;

use App\Modules\Conversation\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 *
 * Never exposes the disk or the storage path: clients only get the
 * authorised download URL.
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->downloadName(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'download_url' => route('attachments.download', $this->resource),
        ];
    }
}
