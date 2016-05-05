<h1>C&oacute;digos internacionales</h1>
<table>
{foreach name=codes key=code item=country from=$codes}
	{if $smarty.foreach.codes.iteration is odd}
	<tr>
	{/if}
	<td align="right"><b>{$code}</b></td><td> - {$country}</td>
	{if $smarty.foreach.codes.iteration is even}
	</tr>
	{/if}
{/foreach}
</table>