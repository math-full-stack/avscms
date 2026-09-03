											<tr>
												<td class="v-align-middle no-border" style="width:70%;">
													Multi Server System
												</td>
												<td class="v-align-middle no-border" style="width:15%;">
													<div class="radio radio-success">
														 <input type="radio" {if $multi_server == '1'}checked="checked"{/if} value="1" name="multi_server" id="multi_server_e">
														 <label class="m-0" for="multi_server_e">Enabled</label>
													</div>												
												</td>
												<td class="v-align-middle no-border" style="width:15%;">
													<div class="radio radio-danger">
														<input type="radio" {if $multi_server == '0'}checked="checked"{/if} value="0" name="multi_server" id="multi_server_d">
														<label class="m-0" for="multi_server_d">Disabled</label>
													</div>
												</td>
											</tr>