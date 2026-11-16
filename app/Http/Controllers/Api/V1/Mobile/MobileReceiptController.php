<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Mobile\ReceiptTextParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileReceiptController extends Controller
{
    use ApiResponse;

    /** Turns on-device OCR text of a receipt into expense fields (vendor, date, total, VAT). */
    public function parse(Request $request, ReceiptTextParser $parser): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:10000']]);

        return $this->successResponse($parser->parse($data['text']), 'Receipt parsed');
    }
}
