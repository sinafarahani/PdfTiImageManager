<div wire:poll.2s>
    @if($started)
        <form wire:submit.prevent="stop">
            @csrf
            <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-4 py-2 m-2">Stop</button>
        </form>
    @else
        <form wire:submit.prevent="start">
            @csrf
            <label for="threads" class="m-2 text-sm font-medium text-gray-900">Number Of Processes:</label>
            <input type="text" id="threads" wire:model="threads" class="input bg-gray-50 rounded-xl border-x-0 border-gray-300 w-16 m-2 text-center text-gray-900 text-sm focus:ring-blue-500 focus:border-blue-500 py-2.5" value="4" required />
            <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-4 py-2 m-2">Start</button>
        </form>
    @endif
</div>
