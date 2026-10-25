<div class="space-y-6">
    <p role="status">{{ $snippets['warning'] }}</p>
    <p>Generated: yes. Applied: unknown. External TCP/UDP reachability: not tested. These are merge templates, not replacement files. Keep all existing keys and ports.</p>
    @foreach (['traefik' => 'Static Traefik: merge children under existing entryPoints', 'compose' => 'Docker Compose: append to existing services.gerbil.ports', 'pangolin' => 'Pangolin: merge allow_raw_resources under existing flags', 'firewall' => 'Firewall: hand off to the host and provider firewall operator'] as $key => $label)
        <section class="space-y-2" x-data="{ status: '' }">
            <label for="pangolin-{{ $key }}" class="font-semibold">{{ $label }}</label>
            <textarea id="pangolin-{{ $key }}" x-ref="snippet" readonly rows="10" class="w-full rounded-lg border p-3 font-mono text-sm" spellcheck="false">{{ $snippets[$key] }}</textarea>
            <button type="button" class="fi-btn rounded-lg border px-4 py-2" aria-controls="pangolin-{{ $key }}"
                x-on:click="navigator.clipboard.writeText($refs.snippet.value).then(() => status = 'Copied').catch(() => status = 'Copy unavailable: select and copy the text manually')">Copy {{ ucfirst($key) }}</button>
            <span role="status" aria-live="polite" x-text="status"></span>
        </section>
    @endforeach
</div>
