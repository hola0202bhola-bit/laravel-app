<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamController extends Controller
{
    public function events()
    {
        $response = new StreamedResponse(function () {
            echo "event: ping\ndata: " . json_encode(['status' => 'connected']) . "\n\n";
            ob_flush();
            flush();
        });

        $response->headers->set('Content-Type', 'text-event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
