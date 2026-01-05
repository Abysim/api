<datalist id="flickr-tags-datalist">
@foreach($allTags as $tag)
<option value="{{ $tag }}">
@endforeach
</datalist>