<form class="form-no-horizontal-spacing" name="add_adv" method="POST" action="{if $edit_mode}index.php?m=advplayeredit&AID={$adv.id}{else}index.php?m=advplayeradd{/if}">
							<div class="row">
								<div class="col-lg-6 col-lg-offset-3 col-md-12">
									<div class="row">
										<div class="col-xs-12 m-b-5">
											<h3>Player Ad <span class="semi-bold">Details</span></h3>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Name</label>
											<div class="col-lg-8">
												<input name="adv_name" type="text" value="{$adv.name}" class="form-control {if $err.name}error{/if}">
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Type</label>
											<div class="col-lg-8">
												<select id="adv_type" name="adv_type" class="form-control">
													{section name=i loop=$player_types}
														<option value="{$player_types[i]}"{if $adv.type == $player_types[i]} selected="selected"{/if}>{$player_types[i]}</option>
													{/section}
												</select>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Group</label>
											<div class="col-lg-8">
												<select id="adv_grp" name="adv_grp" class="form-control {if $err.grp}error{/if}">
													<option value="">Select Player Group</option>
													{section name=i loop=$advgroups}
														<option value="{$advgroups[i].advgrp_name}"{if $adv.grp == $advgroups[i].advgrp_name} selected="selected"{/if}>{$advgroups[i].advgrp_name}</option>
													{/section}
												</select>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Creative</label>
											<div class="col-lg-8">
												<select id="adv_creative" name="adv_creative" class="form-control">
													{section name=i loop=$creatives}
														<option value="{$creatives[i]}"{if $adv.creative == $creatives[i]} selected="selected"{/if}>{$creatives[i]}</option>
													{/section}
												</select>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group" id="row_media_url">
											<label class="col-lg-4 control-label">Media URL</label>
											<div class="col-lg-8">
												<input name="adv_media_url" id="adv_media_url" type="text" value="{$adv.media_url}" class="form-control {if $err.media_url}error{/if}">
												<span class="help-block">Image or video direct URL (image/video creative).</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group" id="row_code">
											<label class="col-lg-4 control-label">Code</label>
											<div class="col-lg-8">
												<textarea name="adv_code" id="adv_code" rows="5" class="form-control {if $err.code}error{/if}" style="resize: vertical">{$adv.code}</textarea>
												<span class="help-block">HTML markup (html creative) or VPAID/VAST tag URL (vast creative).</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Duration (s)</label>
											<div class="col-lg-8">
												<input name="adv_duration" id="adv_duration" type="number" min="0" value="{$adv.duration}" class="form-control {if $err.duration}error{/if}">
												<span class="help-block">Seconds the ad stays visible (fake progress / pause / overlay).</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Fake Progress</label>
											<div class="col-lg-8">
												<div class="radio p-t-9">
													<input id="adv_fp_a" type="radio" name="adv_fake_progress" value="1" {if $adv.fake_progress == '1'}checked="checked"{/if}>
													<label for="adv_fp_a">Enabled</label>
													<input id="adv_fp_i" type="radio" name="adv_fake_progress" value="0" {if $adv.fake_progress == '0'}checked="checked"{/if}>
													<label for="adv_fp_i">Disabled</label>
												</div>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Skip After (s)</label>
											<div class="col-lg-8">
												<input name="adv_skip_after" type="number" min="0" value="{$adv.skip_after}" class="form-control">
												<span class="help-block">0 = no skip button.</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Midroll Schedule (%)</label>
											<div class="col-lg-8">
												<input name="adv_schedule" type="text" value="{$adv.schedule}" class="form-control">
												<span class="help-block">Comma separated points, e.g. 25,50,75 (used by midroll only).</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Cap Per Hour</label>
											<div class="col-lg-8">
												<input name="adv_cap_per_hour" type="number" min="0" max="50" value="{$adv.cap_per_hour}" class="form-control">
												<span class="help-block">Max plays of this ad per hour per visitor (localStorage).</span>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Overlay Position</label>
											<div class="col-lg-8">
												<select id="adv_position" name="adv_position" class="form-control">
													{section name=i loop=$positions}
														<option value="{$positions[i]}"{if $adv.position == $positions[i]} selected="selected"{/if}>{$positions[i]}</option>
													{/section}
												</select>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Device</label>
											<div class="col-lg-8">
												<div class="radio p-t-9">
													<input id="adv_dev_dm" type="radio" name="adv_device" value="dm" {if $adv.device == 'dm'}checked="checked"{/if}>
													<label for="adv_dev_dm">All Devices</label>
													<input id="adv_dev_d" type="radio" name="adv_device" value="d" {if $adv.device == 'd'}checked="checked"{/if}>
													<label for="adv_dev_d">Desktop Only</label>
													<input id="adv_dev_m" type="radio" name="adv_device" value="m" {if $adv.device == 'm'}checked="checked"{/if}>
													<label for="adv_dev_m">Mobile Only</label>
												</div>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="form-group">
											<label class="col-lg-4 control-label">Status</label>
											<div class="col-lg-8">
												<div class="radio p-t-9">
													<input id="adv_status_a" type="radio" name="adv_status" value="1" {if $adv.status == '1'}checked="checked"{/if}>
													<label for="adv_status_a">Active</label>
													<input id="adv_status_i" type="radio" name="adv_status" value="0" {if $adv.status == '0'}checked="checked"{/if}>
													<label for="adv_status_i">Inactive</label>
												</div>
											</div>
											<div class="clearfix"></div>
										</div>
										<div class="col-xs-12 m-b-5">
											<h3>Categories <span class="semi-bold" style="cursor:pointer" onclick="var c=document.getElementsByName('check_all_categories');if(c[0]){c[0].click();}">(check all)</span></h3>
										</div>
										<div class="form-group">
											<div class="col-lg-12">
												{section name=i loop=$categories}
													<label class="checkbox-inline" style="width:31%">
														<input type="checkbox" name="category_{$categories[i].CHID}" value="1" {if $categories[i].checked == 1}checked="checked"{/if}> {$categories[i].name}
													</label>
												{/section}
												<input type="checkbox" id="check_all_categories" name="check_all_categories" style="display:none" {literal}onclick="var inputs=document.getElementsByTagName('input');for(var i=0;i<inputs.length;i++){if(inputs[i].name.indexOf('category_')===0){inputs[i].checked=this.checked;}}"{/literal}>
											</div>
											<div class="clearfix"></div>
										</div>
									</div>
								</div>							
							</div>
							<div class="form-actions">
								<div class="pull-right">
									<input type="submit" name="adv_add" value="Save" class="btn btn-success btn-cons">
									<a href="index.php?m=advplayer&all=1" class="btn btn-white btn-cons">Cancel</a>
								</div>
							</div>
						</form>
						<script type="text/javascript">
						{literal}
						(function() {
							function syncCreative() {
								var c = document.getElementById('adv_creative');
								var type = c ? c.value : 'image';
								var mu = document.getElementById('row_media_url');
								var cd = document.getElementById('row_code');
								if (type === 'image' || type === 'video') {
									mu.style.display = '';
									cd.style.display = 'none';
								} else {
									mu.style.display = 'none';
									cd.style.display = '';
								}
							}
							var c2 = document.getElementById('adv_creative');
							if (c2) { c2.addEventListener('change', syncCreative); syncCreative(); }
						})();
						{/literal}
						</script>