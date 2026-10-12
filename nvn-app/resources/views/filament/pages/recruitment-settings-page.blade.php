{{--
 | Recruitment settings.
 |
 | The preview pane renders the real email layout inside an iframe with a
 | srcdoc, deliberately: an email's own stylesheet dropped straight into this
 | page would restyle the panel around it, and the admin would be looking at
 | something that is neither the email nor the panel. The iframe is sandboxed
 | with nothing allowed, so even though this HTML is ours it can neither run a
 | script nor navigate anywhere.
--}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" size="lg">
                Save settings
            </x-filament::button>
        </div>
    </form>

    @if ($previewed)
        <x-filament::section>
            <x-slot name="heading">Preview — {{ $previewed['label'] }}</x-slot>
            <x-slot name="description">
                Rendered from the wording currently in the form, saved or not, with fictional
                example details. The button is shown without a link, because a preview must not
                hand anybody a working way into somebody's application.
            </x-slot>

            <div class="space-y-3">
                <p class="text-sm">
                    <span class="font-medium">Subject:</span>
                    {{ $previewed['subject'] }}
                </p>

                <iframe
                    title="Email preview"
                    sandbox=""
                    class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
                    style="height: 34rem"
                    srcdoc="{{ $previewed['html'] }}"
                ></iframe>
            </div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
