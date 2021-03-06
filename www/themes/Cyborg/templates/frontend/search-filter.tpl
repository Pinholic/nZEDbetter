{if $covergrp == "movies"}
<form class="form-inline" name="browseby" action="{$smarty.const.WWW_TOP}/search" method="get">
<input class="form-control" style="width: 150px;" id="movietitle" type="text" name="title" value="{$title}" placeholder="Title">
<input class="form-control" style="width: 150px;" id="movieactors" type="text" name="actors" value="{$actors}" placeholder="Actor">
<input class="form-control" style="width: 150px;" id="moviedirector" type="text" name="director" value="{$director}"  placeholder="Director">
<select class="form-control" style="width: 150px;" id="rating" name="rating">
<option class="grouping" value="">Rating... </option>
{foreach from=$ratings item=rate}
<option {if $rating==$rate}selected="selected"{/if} value="{$rate}">{$rate}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="genre" name="genre" placeholder="Genre">
<option class="grouping" value="">Genre... </option>
{foreach from=$genres item=gen}
<option {if $gen==$genre}selected="selected"{/if} value="{$gen}">{$gen}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="year" name="year">
<option class="grouping" value="">Year... </option>
{foreach from=$years item=yr}
<option {if $yr==$year}selected="selected"{/if} value="{$yr}">{$yr}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="category" name="t">
<option class="grouping" value="2000">Category... </option>
{foreach from=$catlist item=ct}
<option {if $ct.ID==$category}selected="selected"{/if} value="{$ct.ID}">{$ct.title}</option>
{/foreach}
</select>
<input class="btn btn-success" type="submit" value="Go">
</form>
{/if}

{if $covergrp == "books"}
<form class="form-inline" name="browseby" action="{$smarty.const.WWW_TOP}/search" method="get">
<input class="form-control" style="width: 150px;" id="author" type="text" name="author" value="{$author}" placeholder="Author">
<input class="form-control" style="width: 150px;" id="title" type="text" name="title" value="{$title}" placeholder="Title">
<input class="btn btn-success" type="submit" value="Go">
</form>
{/if}

{if $covergrp == "music"}
<form class="form-inline" name="browseby" action="{$smarty.const.WWW_TOP}/search" method="get">
<input class="form-control" style="width: 150px;" id="musicartist" type="text" name="artist" value="{$artist}" placeholder="Artist">
<input class="form-control" style="width: 150px;" id="musictitle" type="text" name="title" value="{$title}" placeholder="Title">
<select class="form-control" style="width: 150px;" id="genre" name="genre">
<option class="grouping" value="">Genre... </option>
{foreach from=$genres item=gen}
<option {if $gen.ID == $genre}selected="selected"{/if} value="{$gen.ID}">{$gen.title|escape:"htmlall"}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="year" name="year">
<option class="grouping" value="">Year... </option>
{foreach from=$years item=yr}
<option {if $yr==$year}selected="selected"{/if} value="{$yr}">{$yr}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="category" name="t">
<option class="grouping" value="3000">Category... </option>
{foreach from=$catlist item=ct}
<option {if $ct.ID==$category}selected="selected"{/if} value="{$ct.ID}">{$ct.title}</option>
{/foreach}
</select>
<input class="btn btn-success" type="submit" value="Go">
</form>
{/if}

{if $covergrp == "console"}
<form class="form-inline" name="browseby" action="{$smarty.const.WWW_TOP}/search" method="get">
<input class="form-control" style="width: 150px;" id="title" type="text" name="title" value="{$title}" placeholder="Title">
<input class="form-control" style="width: 150px;" id="platform" type="text" name="platform" value="{$platform}" placeholder="Platform">
<select class="form-control" style="width: 150px;" id="genre" name="genre">
<option class="grouping" value="">Genre... </option>
{foreach from=$genres item=gen}
<option {if $gen.ID == $genre}selected="selected"{/if} value="{$gen.ID}">{$gen.title}</option>
{/foreach}
</select>
<select class="form-control" style="width: 150px;" id="category" name="t">
<option class="grouping" value="1000">Category... </option>
{foreach from=$catlist item=ct}
<option {if $ct.ID==$category}selected="selected"{/if} value="{$ct.ID}">{$ct.title}</option>
{/foreach}
</select>
<input class="btn btn-success" type="submit" value="Go">
</form>
{/if}
