<table>
    <caption>{{ $heading }} — valeurs exactes</caption>
    <thead><tr><th scope="col">Libellé</th>
        @if ($direction)<th scope="col">{{ $direction }}</th>
        @else<th scope="col">Recettes</th><th scope="col">Dépenses</th><th scope="col">Net</th>@endif
        <th scope="col">Nombre de transactions</th></tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr><th scope="row">{{ $row['label'] }}</th>
            @if ($direction)<td>{{ $row['formatted'] }}</td>
            @else<td>{{ $row['revenue_formatted'] }}</td><td>{{ $row['expense_formatted'] }}</td><td>{{ $row['net_formatted'] }}</td>@endif
            <td>{{ $row['count'] }}</td></tr>
    @endforeach
    </tbody>
</table>
