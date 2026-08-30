/**
 * Mirrors InventoryItem::CATEGORIES and StockMovement::TYPES. The units
 * list is frontend-only — `unit` is free text on the server, and this is
 * just a datalist of the common ones. See CLAUDE.md "Known gaps".
 */
export const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
export const UNITS = ['box', 'piece', 'pair', 'pack', 'cartridge', 'bottle', 'tube', 'roll', 'ml'];

export function categoryLabel(category) {
    return category.replace('_', ' ');
}
