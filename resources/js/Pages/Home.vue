<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { GRAPHICS_LIGHTING_OPTIONS, GRAPHICS_QUALITY_OPTIONS, readCheckpoint, readSettings, saveSettings, clearCheckpoint } from '../game/RunState.js';
const props = defineProps({ world: { type: Object, default: null }, firstLevelUrl: { type: String, default: '/game?new=1' } });
const checkpoint = ref(readCheckpoint());
const settings = ref(readSettings());
const optionsOpen = ref(false);
const draftGraphics = ref({ ...settings.value.graphics });
function newGame() { clearCheckpoint(); router.visit(props.firstLevelUrl || '/game?new=1'); }
function continueGame() { if (checkpoint.value) router.visit(checkpoint.value.url); }
function openOptions() { draftGraphics.value = { ...settings.value.graphics }; optionsOpen.value = true; }
function closeOptions() { optionsOpen.value = false; }
function applyOptions() {
    settings.value = { ...settings.value, graphics: { ...draftGraphics.value } };
    saveSettings(settings.value);
    optionsOpen.value = false;
}
</script>
<template>
    <main class="title-screen">
        <div class="title-scene" aria-hidden="true"><div class="distant-gate"><i /><i /><i /></div><div class="title-floor" /><div class="title-mist" /></div>
        <div class="title-corner top-left" /><div class="title-corner top-right" /><div class="title-corner bottom-left" /><div class="title-corner bottom-right" />
        <div class="title-content">
            <p class="title-kicker">A dark fantasy dungeon crawler</p>
            <div class="title-rune" aria-hidden="true">✦</div>
            <h1>THE ASHEN<br /><span>REALMS</span></h1>
            <div class="title-divider"><i /><span>THE RUINED MARCHES</span><i /></div>
            <p class="title-description">{{ world?.description || 'A fallen kingdom where ancient magic stirs beneath forgotten stone.' }}</p>
            <nav class="title-actions" aria-label="Main menu">
                <button v-if="checkpoint" class="game-button primary" @click="continueGame">Continue your journey <span>→</span></button>
                <button :class="checkpoint ? 'title-secondary' : 'game-button primary'" @click="newGame">New journey <span v-if="!checkpoint">→</span></button>
                <button class="title-secondary options-trigger" @click="openOptions">Options</button>
            </nav>
            <p v-if="checkpoint" class="title-save">Chapter checkpoint found · {{ new Date(checkpoint.savedAt).toLocaleDateString() }}</p>
            <p class="title-invitation">Four chapters. Three lost sigils. One way out.</p>
        </div>
        <footer class="title-footer"><span>WASD MOVE <b>·</b> MOUSE AIM & FIRE</span><span>HEADPHONES RECOMMENDED</span></footer>
        <div v-if="optionsOpen" class="options-backdrop" @click.self="closeOptions">
            <section class="options-panel" role="dialog" aria-modal="true" aria-labelledby="options-title">
                <p class="options-kicker">Configuration</p>
                <h2 id="options-title">Graphics options</h2>
                <p class="options-description">Changes apply the next time the dungeon boots.</p>
                <div class="options-list">
                    <label class="option-row">
                        <span><strong>Render scale</strong><small>Lower values improve integrated GPU performance.</small></span>
                        <select v-model="draftGraphics.quality" aria-label="Render scale">
                            <option v-for="option in GRAPHICS_QUALITY_OPTIONS" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                    </label>
                    <label class="option-row">
                        <span><strong>Anti-aliasing</strong><small>Smooths edges at an additional GPU cost.</small></span>
                        <input v-model="draftGraphics.antialias" type="checkbox" aria-label="Anti-aliasing" />
                    </label>
                    <label class="option-row">
                        <span><strong>Dynamic lighting</strong><small>Choose how far torch lights remain active.</small></span>
                        <select v-model="draftGraphics.lighting" aria-label="Dynamic lighting">
                            <option v-for="option in GRAPHICS_LIGHTING_OPTIONS" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                    </label>
                </div>
                <div class="options-actions">
                    <button class="options-button muted" @click="closeOptions">Back</button>
                    <button class="options-button" @click="applyOptions">Save options</button>
                </div>
            </section>
        </div>
    </main>
</template>
<style scoped>
.title-screen{height:100dvh;width:100%;position:relative;overflow:auto;background:#070e0b;color:#e5d7b5;display:flex;align-items:center;justify-content:center;text-align:center;padding:65px 25px;font-family:Georgia,serif}.title-scene{position:absolute;inset:0;overflow:hidden;background:radial-gradient(ellipse at 50% 42%,#3e544236,#090f0c 68%),repeating-linear-gradient(0deg,transparent 0 71px,#bcb39305 72px 73px),repeating-linear-gradient(90deg,transparent 0 118px,#bcb39304 119px 120px)}.distant-gate{position:absolute;left:50%;top:8%;width:480px;height:700px;transform:translateX(-50%);border:38px solid #27372b44;border-bottom:0;border-radius:240px 240px 0 0;box-shadow:0 0 0 3px #7f7c4420,0 0 100px #5a8c4630,inset 0 0 100px #6c966c14}.distant-gate i{position:absolute;background:#5c816018;width:2px;height:160%;top:-10%;transform-origin:top}.distant-gate i:first-child{left:35%;transform:rotate(25deg)}.distant-gate i:nth-child(2){right:35%;transform:rotate(-25deg)}.distant-gate i:last-child{left:50%}.title-floor{position:absolute;bottom:-15%;width:160%;height:40%;left:-30%;background:repeating-linear-gradient(90deg,transparent 0 100px,#63896a12 101px 102px),repeating-linear-gradient(0deg,transparent 0 65px,#63896a12 66px 67px);transform:perspective(180px) rotateX(46deg);mask-image:linear-gradient(transparent,#000)}.title-mist{position:absolute;inset:0;background:radial-gradient(ellipse at 25% 85%,#4d70461a,transparent 45%),linear-gradient(transparent 60%,#08140caa);animation:mist 12s ease-in-out infinite alternate}.title-content{position:relative;z-index:1;max-width:600px}.title-kicker{font:9px system-ui;letter-spacing:.32em;color:#7f937c;text-transform:uppercase;margin:0 0 22px}.title-rune{color:#bba574;font-size:29px;margin-bottom:12px;text-shadow:0 0 24px #a5c58f33}.title-content h1{font-size:clamp(40px,5.5vw,75px);font-weight:400;line-height:1.04;letter-spacing:.045em;margin:0;color:#ebe0c1;text-shadow:0 3px 40px #caca8c15}.title-content h1 span{font-size:1.22em;letter-spacing:.085em;color:#c5b37f}.title-divider{display:flex;align-items:center;justify-content:center;gap:18px;margin:23px 0}.title-divider i{height:1px;width:38px;background:#95805270}.title-divider span{font:9px system-ui;letter-spacing:.25em;color:#9d926d}.title-description{font:12px/1.8 system-ui;color:#82937e;max-width:330px;margin:0 auto}.title-actions{max-width:320px;margin:31px auto 0}.title-actions .primary{margin:0}.title-secondary{display:block;width:100%;padding:18px 12px;margin-top:10px;color:#a1aa8f;font:10px system-ui;letter-spacing:.19em;text-transform:uppercase;cursor:pointer}.title-secondary:hover{color:#e0c993}.title-invitation{font:italic 12px Georgia;color:#778871;margin-top:30px}.title-save{font:9px system-ui;color:#697e67;margin-top:5px}.title-corner{position:absolute;width:44px;height:44px;border:1px solid #b3a37140}.top-left{top:28px;left:28px;border-right:0;border-bottom:0}.top-right{top:28px;right:28px;border-left:0;border-bottom:0}.bottom-left{bottom:28px;left:28px;border-right:0;border-top:0}.bottom-right{bottom:28px;right:28px;border-left:0;border-top:0}.title-footer{position:absolute;bottom:38px;left:60px;right:60px;display:flex;justify-content:space-between;gap:20px;font:8px system-ui;color:#5f725e;letter-spacing:.1em}.title-footer b{margin:0 9px;color:#82754e}@keyframes mist{to{opacity:.45;transform:translateX(25px)}}@media(prefers-reduced-motion:reduce){.title-mist{animation:none}}@media(max-width:700px){.title-footer{left:30px;right:30px;justify-content:center;font-size:7px}.title-footer>span:last-child{display:none}.title-content h1{font-size:48px}}@media(max-height:700px){.title-kicker{margin-bottom:10px}.title-rune{font-size:20px}.title-content h1{font-size:46px}.title-divider{margin:15px 0}.title-actions{margin-top:23px}.title-invitation{margin-top:20px}}
.options-trigger{margin-top:10px}.options-backdrop{position:fixed;inset:0;z-index:20;display:flex;align-items:center;justify-content:center;padding:24px;background:#020705cc;backdrop-filter:blur(10px)}.options-panel{width:min(100%,520px);padding:34px;background:linear-gradient(145deg,#15221bcc,#07100de8);border:1px solid #bca67066;box-shadow:0 20px 80px #000b;text-align:left}.options-kicker{margin:0 0 9px;font:9px system-ui;letter-spacing:.25em;text-transform:uppercase;color:#9c8a60}.options-panel h2{margin:0;color:#ecdfbd;font:34px Georgia;font-weight:400}.options-description{margin:9px 0 26px;font:12px/1.6 system-ui;color:#899b8b}.options-list{border-top:1px solid #bca6702b}.option-row{display:flex;align-items:center;justify-content:space-between;gap:22px;padding:18px 0;border-bottom:1px solid #bca6702b;color:#d7c9a7;cursor:pointer}.option-row span{display:flex;flex-direction:column;gap:5px}.option-row strong{font:13px system-ui;color:#d9cda9}.option-row small{font:10px/1.4 system-ui;color:#849486}.option-row select{min-width:130px;padding:8px 10px;border:1px solid #9d8a5b66;background:#0b1510;color:#d8cba8;font:11px system-ui}.option-row input{width:18px;height:18px;accent-color:#bba574}.options-actions{display:flex;justify-content:flex-end;gap:16px;margin-top:28px}.options-button{padding:11px 16px;border:1px solid #bca67077;background:#bca670;color:#172019;font:10px system-ui;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}.options-button.muted{background:transparent;color:#a2ad99}.options-button:hover{filter:brightness(1.15)}@media(max-width:600px){.options-panel{padding:25px 20px}.options-panel h2{font-size:29px}.option-row{align-items:flex-start;gap:12px}.option-row select{min-width:112px}.options-actions{margin-top:20px}}
</style>
