import * as pc from 'playcanvas';

export function colorFromRgb(rgb) {
    return new pc.Color(...rgb);
}

export function falloffModeFromName(name) {
    return name === 'inverse_squared'
        ? pc.LIGHTFALLOFF_INVERSESQUARED
        : pc.LIGHTFALLOFF_LINEAR;
}

export function toneMappingFromName(name) {
    return {
        linear: pc.TONEMAP_LINEAR,
        filmic: pc.TONEMAP_FILMIC,
        hejl: pc.TONEMAP_HEJL,
        aces: pc.TONEMAP_ACES,
        aces2: pc.TONEMAP_ACES2,
        neutral: pc.TONEMAP_NEUTRAL,
        none: pc.TONEMAP_NONE,
    }[name] ?? pc.TONEMAP_NEUTRAL;
}

export function fogTypeFromName(name) {
    return {
        none: pc.FOG_NONE,
        linear: pc.FOG_LINEAR,
        exp: pc.FOG_EXP,
        exp2: pc.FOG_EXP2,
    }[name] ?? pc.FOG_LINEAR;
}
