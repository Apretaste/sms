<h1>C&oacute;digos internacionales</h1>

<center>
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
</center>

{space10}

<center>
	{button caption="Enviar SMS" href="SMS" desc="Escriba el numero de telefono, por ejemplo 19876543210|Escriba el texto a enviar" popup="true"}
</center>
