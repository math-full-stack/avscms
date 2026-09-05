<div class="modal fade" id="thumbnailsModal" tabindex="-1" role="dialog" aria-labelledby="thumbnailsModalLabel" aria-hidden="true" style="display: none;">
<div id="thumbnailsModalDialog" class="modal-dialog">
	<div class="modal-content">
		<div class="modal-header">
			<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
			<h4 id="thumbnailsModalLabel" class="semi-bold">Thumbnails - Video <span id="thumbnails-id-span"></span></h4>
		</div>
		<div class="modal-body">
			<input id="thumbnails-id" name="thumbnails-id" type="hidden" value=""/>
			<input id="thumbnails-default" name="thumbnails-default" type="hidden" value=""/>
			<input id="thumbnails-opt" name="thumbnails-opt" type="hidden" value=""/>
			<input id="thumbnails-processing" name="thumbnails-processing" type="hidden" value="0"/>

			<div class="row thumbnails-toolbar m-b-10">
				<div class="col-xs-12">
					<span class="text-bold">Capas em rotação: <span id="thumbnails-opt-count">0</span></span>
					<span class="m-l-10 text-muted font-11">(alternam nos cards a cada visita)</span>
					<span class="pull-right">
						<button type="button" id="thumbnails-opt-all" class="btn btn-white btn-xs">Selecionar todas</button>
						<button type="button" id="thumbnails-opt-clear" class="btn btn-white btn-xs">Limpar</button>
					</span>
				</div>
			</div>

			<div class="row form-row thumb-row">
				<div id="thumbnails-container"></div>
				<div id="thumbnails-container-rel" style="display: none"></div>
				<div class="clearfix"></div>
				<div id="thumbnails-loading" class="thumbnails-loading" style="display: none"><i class="loader"></i></div>
			</div>

			<div class="row thumb-legend m-t-10">
				<div class="col-xs-12">
					<span class="thumb-legend-item"><span class="thumb-legend-dot main"></span> Capa principal (fallback)</span>
					<span class="thumb-legend-item m-l-10"><span class="thumb-legend-dot opt"><i class="fa fa-check"></i></span> Rotaciona nos cards</span>
					<span class="thumb-legend-item m-l-10"><i class="fa fa-star thumb-legend-star"></i> Definir principal</span>
				</div>
			</div>
		</div>
		<div class="modal-footer">
			<div id="regen-options" class="row">
				<div class="col-xs-12 m-b-10">
					<div class="pull-left">
						<div class="checkbox check-default pull-left">
							<input name="thumbnails-remove-bb" id="thumbnails-remove-bb" type="checkbox">
							<label for="thumbnails-remove-bb">Remove Black Bars</label>
						</div>
						<div class="checkbox check-default pull-left m-0">
							<input name="thumbnails-keep-ar" id="thumbnails-keep-ar" type="checkbox">
							<label for="thumbnails-keep-ar">Keep Aspect Ratio</label>
						</div>						
					</div>
					<div class="btn-group pull-right">
						<button type="button" id="thumbnails-regen-all" class="btn btn-white btn-icon">Regenerate: All</button>
						<button type="button" id="thumbnails-regen-main" class="btn btn-white btn-icon">Main</button>
						<button type="button" id="thumbnails-regen-player" class="btn btn-white btn-icon">Player</button>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-xs-12">
					<button type="button" class="btn btn-default pull-left" data-dismiss="modal">Close</button>
					<button type="button" id="thumbnails-reset" class="btn btn-white btn-icon">Reload</button>			
					<button type="button" id="thumbnails-save" class="btn btn-success">Save</button>				
				</div>
			</div>			
		</div>
				
	</div>
 
</div>
 
</div>