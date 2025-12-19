<?php

/**
 * Mock Document class for testing
 *
 * This mocks OpenEMR's Document class used for document management.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

/**
 * Mock Document class in global namespace to match OpenEMR
 */
class Document
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private static array $mockDocuments = [];

    private int $id;

    /**
     * @var array<string, mixed>
     */
    private array $data;

    public function __construct(int|string $id)
    {
        $this->id = (int)$id;
        $this->data = self::$mockDocuments[$this->id] ?? [
            'name' => '',
            'mimetype' => 'application/pdf',
            'foreign_id' => 0,
            'data' => '',
        ];
    }

    /**
     * Set mock document data for testing
     *
     * @param int $id Document ID
     * @param array<string, mixed> $data Document data
     */
    public static function setMockDocument(int $id, array $data): void
    {
        self::$mockDocuments[$id] = array_merge([
            'name' => '',
            'mimetype' => 'application/pdf',
            'foreign_id' => 0,
            'data' => '',
        ], $data);
    }

    /**
     * Clear all mock documents
     */
    public static function clearMockDocuments(): void
    {
        self::$mockDocuments = [];
    }

    public function get_name(): string
    {
        return $this->data['name'] ?? '';
    }

    public function get_mimetype(): string
    {
        return $this->data['mimetype'] ?? 'application/pdf';
    }

    public function get_foreign_id(): int
    {
        return (int)($this->data['foreign_id'] ?? 0);
    }

    /**
     * Get document data (content)
     *
     * @return string Document content
     * @throws \Exception If configured to throw
     */
    public function get_data(): string
    {
        if (isset($this->data['throw_exception']) && $this->data['throw_exception']) {
            throw new \Exception($this->data['exception_message'] ?? 'Document error');
        }
        return $this->data['data'] ?? '';
    }
}
