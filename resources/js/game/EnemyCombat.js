export const ENEMY_TYPES = Object.freeze({
    imp: { name: 'Ash Imp', health: 52, speed: 4.4, radius: 0.44, height: 1.8, width: 1.55, sight: 29, range: 1.65, damage: 9, windup: 0.42, cooldown: 1.2 },
    acolyte: { name: 'Hollow Acolyte', health: 72, speed: 2.8, radius: 0.46, height: 2.25, width: 1.7, sight: 34, range: 23, damage: 13, windup: 0.85, cooldown: 2.8, projectileSpeed: 11 },
    warden: { name: 'Iron Warden', health: 190, speed: 2.4, radius: 0.63, height: 2.65, width: 2.05, sight: 31, range: 2.15, damage: 22, windup: 0.78, cooldown: 1.8 },
});

/** First intersection with an upright body; expanded by projectile radius.
 * A horizontal-only proximity test would hit enemies on different elevations.
 */
export function segmentBodyIntersection(from, to, position, radius, height, projectileRadius = 0) {
    const padding = Math.max(0, projectileRadius);
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const dz = to.z - from.z;
    const ox = from.x - position.x;
    const oz = from.z - position.z;
    const bodyRadius = radius + padding;
    const a = dx * dx + dz * dz;
    const c = ox * ox + oz * oz - bodyRadius * bodyRadius;
    let enter = 0;
    let leave = 1;

    if (a < 1e-12) {
        if (c > 0) return null;
    } else {
        const b = 2 * (ox * dx + oz * dz);
        const discriminant = b * b - 4 * a * c;
        if (discriminant < 0) return null;
        const root = Math.sqrt(discriminant);
        enter = Math.max(enter, (-b - root) / (2 * a));
        leave = Math.min(leave, (-b + root) / (2 * a));
    }

    const bottom = position.y - padding;
    const top = position.y + height + padding;
    if (Math.abs(dy) < 1e-12) {
        if (from.y < bottom || from.y > top) return null;
    } else {
        const bottomTime = (bottom - from.y) / dy;
        const topTime = (top - from.y) / dy;
        enter = Math.max(enter, Math.min(bottomTime, topTime));
        leave = Math.min(leave, Math.max(bottomTime, topTime));
    }

    return enter <= leave && leave >= 0 && enter <= 1 ? Math.max(0, enter) : null;
}

export function nearestEnemyHit(from, to, enemies, projectileRadius = 0) {
    let nearest = null;
    for (const enemy of enemies) {
        if (enemy.health <= 0) continue;
        const config = enemy.config ?? ENEMY_TYPES[enemy.type] ?? ENEMY_TYPES.imp;
        const t = segmentBodyIntersection(from, to, enemy.position, config.radius, config.height, projectileRadius);
        if (t !== null && (!nearest || t < nearest.t)) nearest = { enemy, t };
    }
    return nearest;
}
