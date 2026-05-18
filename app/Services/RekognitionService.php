<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;

class RekognitionService
{
    protected $client;

    public function __construct()
    {
        $this->client = new RekognitionClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }

    // =========================
    // COMPARE FACES
    // =========================
    public function compareFaces($sourceImage, $targetImage)
    {
        $result = $this->client->compareFaces([
            'SourceImage' => ['Bytes' => file_get_contents($sourceImage)],
            'TargetImage' => ['Bytes' => file_get_contents($targetImage)],
            'SimilarityThreshold' => 80,
        ]);

        return $result['FaceMatches'][0]['Similarity'] ?? 0;
    }

    // =========================
    // SEARCH DUPLICATE
    // =========================
    public function searchDuplicate($image)
    {
        $result = $this->client->searchFacesByImage([
            'CollectionId' => env('REKOGNITION_COLLECTION_ID'),
            'Image' => ['Bytes' => file_get_contents($image)],
            'FaceMatchThreshold' => 90,
            'MaxFaces' => 1,
        ]);

        return $result['FaceMatches'] ?? [];
    }

    // =========================
    // INDEX FACE
    // =========================
    public function indexFace($image, $userId)
    {
        $result = $this->client->indexFaces([
            'CollectionId' => env('REKOGNITION_COLLECTION_ID'),
            'Image' => ['Bytes' => file_get_contents($image)],
            'ExternalImageId' => (string) $userId,
        ]);

        return $result['FaceRecords'][0]['Face']['FaceId'] ?? null;
    }
}