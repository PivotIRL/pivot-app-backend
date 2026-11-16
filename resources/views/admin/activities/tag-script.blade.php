<script>
const activityTagOptions = {
    sensory_tags: ['Movement','Sight','Smell','Sound','Touch','Cognitive','Focus','Proprioceptive','Rhythm','Tactile','Vestibular','Visual'],
    outcome_tag: ['Calming','Energising','Focus','Social Connection','Agility','Alertness','Balance','Comprehension','Control','Core','Energy','Flexibility','Mental Clarity','Mindfulness','Mobility','Posture','Relaxation','Resilience','Rest','Sleep','Stamina','Strength','Stress Release','Stress Relief','Tension Release'],
    mood_match: ['Anxiety','Anxious','Boredom','Distracted','Doomscrolling','Excited','Frustrated','Good','Happy','Low','Neutral','Overwhelmed','Peaceful','Stiff','Stressed','Tired']
};
function parseActivityTags(value) {
    if (Array.isArray(value)) return value;
    if (!value) return [];
    try { const parsed = JSON.parse(value); if (Array.isArray(parsed)) return parsed; if (typeof parsed === 'string') return parseActivityTags(parsed); } catch (_) {}
    // Existing rows store single-quoted list strings; preserve custom tag spelling.
    if (value.trim().startsWith('[')) {
        const matches = [...value.matchAll(/'((?:\\.|[^'\\])*)'/g)];
        if (matches.length) return matches.map(m => m[1].replace(/\\(['\\])/g, '$1'));
    }
    return value.split(',').map(tag => tag.trim()).filter(Boolean);
}
function setActivityTags(id, value) {
    const input = document.getElementById(id);
    const box = input.closest('.activity-tags');
    const tags = [...new Set(parseActivityTags(value).map(tag => String(tag).trim()).filter(Boolean))];
    input.value = JSON.stringify(tags);
    const selected = box.querySelector('.tag-selected');
    selected.replaceChildren();
    tags.forEach(tag => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-secondary btn-sm';
        button.textContent = tag + ' ×';
        button.setAttribute('aria-label', 'Remove ' + tag);
        button.onclick = () => setActivityTags(id, tags.filter(item => item !== tag));
        selected.append(button);
    });
    const options = box.querySelector('.tag-options');
    options.replaceChildren(new Option('Select a tag…', ''));
    activityTagOptions[box.dataset.field].filter(tag => !tags.includes(tag)).forEach(tag => options.add(new Option(tag, tag)));
}
function setActivitySelectOrInput(id, value) {
    const input = document.getElementById(id);
    // Keep existing legacy values visible when editing older activities.
    if (input.tagName === 'SELECT' && value && ![...input.options].some(option => option.value === String(value))) input.add(new Option(value, value));
    input.value = value;
}
document.querySelectorAll('.activity-tags').forEach(box => {
    const id = box.dataset.input;
    setActivityTags(id, document.getElementById(id).value);
    box.querySelector('.tag-options').addEventListener('change', event => {
        if (event.target.value) setActivityTags(id, [...parseActivityTags(document.getElementById(id).value), event.target.value]);
    });
    const custom = box.querySelector('.tag-custom');
    const addCustom = () => {
        if (custom && custom.value.trim()) {
            setActivityTags(id, [...parseActivityTags(document.getElementById(id).value), custom.value.trim()]);
            custom.value = '';
        }
    };
    custom?.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); addCustom(); } });
    custom?.addEventListener('blur', addCustom);
    box.closest('form').addEventListener('submit', addCustom);
});
</script>
