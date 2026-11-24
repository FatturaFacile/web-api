<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Support;

/**
 * A job position of the company.
 */
final readonly class DirectoryPosition
{
    public function __construct(
        public string $id,
        public string $code,
        public string $title,
        public ?string $departmentId,
        public ?string $departmentName,
    ) {}

    /**
     * @return array{id: string, code: string, title: string, department_id: string|null, department_name: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'department_id' => $this->departmentId, 'department_name' => $this->departmentName];
    }
}
