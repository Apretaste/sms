<h1>SMS enviado correctamente</h1>
<h2>Mensaje enviado</h2>
<p>{$msg}</p>
<h2>Destinatario</h2>
<p>{$cellnumber}</p>
{if $bodyextra}
<h2>Parte del mensaje no enviado</h2>
<p>{$bodyextra}</p>
{/if}
<h2>Cr&eacute;dito actual</h2>
<p>${$credit}</p>