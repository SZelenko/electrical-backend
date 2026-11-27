<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * Catalogue of panel conflict problems; the backing value is the RFC 7807 `type`.
 */
enum PanelError: string
{
    case NameConflict = 'panel_name_conflict';
    case VersionConflict = 'panel_version_conflict';
    case NotInTrash = 'panel_not_in_trash';
    case ContentIdConflict = 'panel_content_id_conflict';

    public function status(): int
    {
        return 409;
    }

    public function title(): string
    {
        return match ($this) {
            self::NameConflict => 'A panel with this name already exists.',
            self::VersionConflict => 'The panel was changed by someone else; reload it and retry.',
            self::NotInTrash => 'The panel is not in the trash.',
            self::ContentIdConflict => 'An item id of the content is already used by another panel.',
        };
    }
}
