export default {
	data () {
		return {
			nameSpace: 'workExperience',
			isFetchRecord: false
		}
	},
	methods: {
		showHideNewRecordForm (status, experiance) {
			var experiance   = experiance || false,
			    experiance   = jQuery.isEmptyObject(experiance) ? false : experiance;

			if ( experiance ) {
			    if ( status === 'toggle' ) {
			        experiance.editMode = experiance.editMode ? false : true;
			    } else {
			        experiance.editMode = status;
			    }
			} else {

			    this.$store.commit(this.nameSpace+'/showHideNewRecordForm', status);
			}
		},

		recordDelete (deletedId, callback) {
			var self = this;

			var form_data = {
	            data: {
	            	delete: deletedId,
	            	class: 'Work_Experience',
					method: 'delete'
	            },

	            success: function(res) {
	            	
	            	self.$store.commit(self.nameSpace + '/afterDelete', deletedId);
	            	if (typeof callback === 'function') {
	                    callback(deletedId);
	                } 
	                
	            },

	            error: function(res) {
	            	self.show_spinner = false;
	            	// Showing error
	                res.error.map( function( value, index ) {
	                    hrm.toastr.error(value);
	                });
	            }
	        };

	        this.httpRequest('hrm_delete_record', form_data);
		},

		updateRecord (args) {
			var self = this;

			var form_data = {
                data: args.data,

                beforeSend () {
                	self.loadingStart(
                		'hrm-edit-form-'+args.data.id, 
                		{animationClass: 'preloader-update-animation'}
                	);
                },

                success: function(res) {
                	self.recordMeta(res.data);

                	self.$store.commit( self.nameSpace + '/updateRecord', res.data );
                	self.loadingStop('hrm-edit-form-'+res.data.id);

                	if (typeof args.callback === 'function') {
                        args.callback(true, res);
                    } 
                    
                },

                error: function(res) {
                	
                	// Showing error
                    res.error.map( function( value, index ) {
                        hrm.toastr.error(value);
                    });

                    if (typeof args.callback === 'function') {
                        args.callback(false, res);
                    } 
                }
            };

            this.httpRequest('hrm_update_record', form_data);
		},

		addNewRecord (args) {
			var self = this;

			var form_data = {
                data: args.data,

                beforeSend () {
                	self.loadingStart(
                		'hrm-hidden-form', 
                		{animationClass: 'preloader-update-animation'}
                	);
                },

                success: function(res) {
                	self.recordMeta(res.data);
                	self.$store.commit( self.nameSpace + '/setRecord', res.data );
                	self.$store.commit( self.nameSpace + '/updatePaginationAfterNewRecord' );

                	self.loadingStop('hrm-hidden-form');

                	if (typeof args.callback === 'function') {
                        args.callback(true, res);
                    } 
                    
                    hrm.Toastr.success(res.message);
                },

                error: function(res) {

                	// Showing error
                    res.error.map( function( value, index ) {
                        hrm.Toastr.error(value);
                    });

                    if (typeof args.callback === 'function') {
                        args.callback(false, res);
                    } 
                }
            };

            this.httpRequest('hrm_insert_record', form_data);
		},

		getRecords (args) {
			var self = this;

			//if (self.$route.query.filter == 'active') {
				self.filter(args);
			// } else {
			// 	self.fetchRecords(args);
			// }
		},

		fetchRecords () {
			var self = this;

			var postData = {
				'class': 'Work_Experience',
				'method': 'gets',
				'transformers': 'Work_Experience_Transformer',
				'page': this.$route.params.current_page_number
			};
			
            var request_data = {
                data: postData,
                success: function(res) {
                	res.data.forEach(function(record) {
                		self.recordMeta(record);
                	});
                    
                    self.$store.commit( self.nameSpace + '/setRecords', res.data );
                    self.$store.commit( self.nameSpace + '/setPagination', res.meta.pagination );
                }
            };

            self.httpRequest('hrm_get_records', request_data);
		},

		recordMeta (record) {
			record.editMode = false;
		},

		filter (callback) {
			var self = this;
			this.$route.query['page'] = this.$route.params.current_page_number;
			this.$route.query['employee_id'] = this.$route.params.employeeId;

			var form_data = {
	            data: this.$route.query,

	            beforeSend () {
	            	self.loadingStart('hrm-list-table');
	            },

	            success: function(res) {
	            	res.data.forEach(function(record) {
                		self.recordMeta(record);
                	});

	            	self.$store.commit(self.nameSpace + '/setRecords', res.data);
	            	self.$store.commit( self.nameSpace + '/setPagination', res.meta.pagination );
	            	self.loadingStop('hrm-list-table');
	            	self.isFetchRecord = true;
	            	
	            	if (typeof callback === 'function') {
	                    callback(true, res);
	                } 
	                
	            },

	            error: function(res) {
	            	self.show_spinner = false;
	            	// Showing error
	                res.error.map( function( value, index ) {
	                    hrm.toastr.error(value);
	                });

	                if (typeof args.callback === 'function') {
	                    callback(false, res);
	                } 
	            }
	        };

	        this.httpRequest('hrm_experiance_filter', form_data);
		},

		editFormValidation (fields, postData) {
        	var isFormValidate = true;

			fields.forEach(function(val) {
				if(
					val.editable !== false
						&&
					val.required === true
						&&
					!postData[val.name]
				) {
					hrm.Toastr.error(val.label + ' is required!');
					isFormValidate = false;
				}
			});

			return isFormValidate;
        }
	}	
}