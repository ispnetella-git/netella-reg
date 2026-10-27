{{-- Panel Netella-Reg na stronie Overview urządzenia. Sekcje dokładają moduły. --}}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <i class="fa fa-list-alt fa-lg"></i>
                <strong><a href="{{ $pageUrl }}">Netella-Reg</a></strong>
            </div>
            <div class="panel-body">
                @foreach($sections as $section)
                    {!! $section !!}
                @endforeach
            </div>
        </div>
    </div>
</div>
